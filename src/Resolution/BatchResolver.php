<?php

namespace GiacomoMasseroni\AsyncColumn\Resolution;

use Carbon\CarbonInterface;
use Closure;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Turns a batch of client-supplied cell tokens into rendered HTML.
 *
 * This is the package's security boundary: tokens arrive from the browser and are
 * therefore attacker-controllable. The following rules keep a forged token from
 * reaching data, or even the existence of a column, the requesting user could not
 * already see:
 *
 *  - Records are hydrated exclusively through the table's OWN query, scoped
 *    exactly the way Filament itself scopes a single-record lookup (see
 *    scopedQuery() below) - never Model::find(). Tenancy, global scopes, table
 *    filters and any ->modifyQueryUsing() constraints therefore apply. A key for
 *    a row outside that query simply never comes back from ->get(), so no cell
 *    is produced for it.
 *  - Only columns that are actually defined on the table (and are AsyncColumn
 *    instances) are resolved. An unknown or mistyped column name is silently
 *    dropped rather than turned into an error that could leak table structure.
 *  - A column that exists but is currently hidden ($column->isVisible() === false)
 *    or toggled off by the user ($column->isToggledHidden() === true) is rejected
 *    the same way. Table::getColumn() would return every registered column
 *    regardless of visibility, so this looks the token's column up through
 *    Table::getVisibleColumns() instead - without that, a forged token could
 *    reveal a value the table itself never rendered a placeholder for.
 *
 * The incoming token array is also capped at config('async-column.max_batch_size')
 * (truncated, never an error) so a single request cannot force arbitrarily many
 * resolver invocations, and a failure while hydrating a column's records (as
 * opposed to a failure inside one resolver) degrades that column's cells to error
 * cells instead of aborting the whole batch.
 */
class BatchResolver
{
    /**
     * Fallback used both when config('async-column.max_batch_size') is absent
     * and when it resolves to something <= 0 (including a malformed env value:
     * (int) casts a non-numeric ASYNC_COLUMN_MAX_BATCH_SIZE to 0). A cap of 0
     * or less is nonsensical as "the maximum number of tokens to process" - it
     * must never be read as "no cap".
     */
    protected const DEFAULT_MAX_BATCH_SIZE = 200;

    /**
     * @param  array<int, mixed>  $tokens
     * @return array{cells: array<string, array{html: string, error: bool}>}
     */
    public function resolve(HasTable $component, array $tokens): array
    {
        $table = $component->getTable();

        $maxBatchSize = (int) config('async-column.max_batch_size', self::DEFAULT_MAX_BATCH_SIZE);

        if ($maxBatchSize <= 0) {
            $maxBatchSize = self::DEFAULT_MAX_BATCH_SIZE;
        }

        $tokens = array_slice($tokens, 0, $maxBatchSize);

        // Group valid tokens by column, keyed by the (still-untrusted) record key,
        // so each column's records can be loaded in a single query. The resolved
        // AsyncColumn instance travels alongside its tokens so the resolution loop
        // below never has to re-derive or re-validate it.
        $groups = [];

        foreach ($tokens as $rawToken) {
            if (! is_string($rawToken)) {
                continue;
            }

            $token = CellToken::decode($rawToken);

            if (! $token instanceof CellToken) {
                continue;
            }

            // Table::getVisibleColumns() is array_filter(getColumns(), fn ($c) =>
            // $c->isVisible() && ! $c->isToggledHidden()) (verified against
            // filament/tables' Table\Concerns\HasColumns), memoised behind
            // $cachedVisibleColumns and keyed by column name exactly like
            // getColumns() - so this single lookup is both the "only columns
            // genuinely defined on this table" check and the "currently visible,
            // not toggled off" check getColumn() alone doesn't give us, without
            // re-evaluating a policy-backed ->visible() a second time per token.
            $column = $table->getVisibleColumns()[$token->columnName] ?? null;

            // Only columns actually defined on this table - currently visible,
            // not toggled off - are resolvable. A forged, hidden, or foreign
            // column name simply produces nothing - never an error, never a
            // callable built from client input.
            if (! $column instanceof AsyncColumn) {
                continue;
            }

            $groups[$token->columnName]['column'] ??= $column;
            $groups[$token->columnName]['tokens'][$token->recordKey] = $rawToken;
        }

        $cells = [];

        foreach ($groups as ['column' => $column, 'tokens' => $tokensByRecordKey]) {
            try {
                $query = $this->scopedQuery($component, $table);

                if ($query === null) {
                    continue;
                }

                // Match what Filament's own single-record resolution
                // (HasRecords::resolveTableRecord()) does after scoping the query:
                // every visible column's aggregate (counts/avg/sum/...) selects must
                // be present too, or a resolver reading e.g. $record->comments_count
                // would silently see null instead of the value it would have seen in
                // the table's own listing query.
                foreach ($table->getVisibleColumns() as $visibleColumn) {
                    $visibleColumn->applyRelationshipAggregates($query);
                }

                // Hydrate through the table's OWN (now filter-scoped) query. A forged
                // record key cannot reach a row the user could not already see - it
                // simply never comes back here.
                $records = $query->whereKey(array_keys($tokensByRecordKey))->get();
            } catch (Throwable $exception) {
                // A query-level failure (bad connection, broken filter, broken
                // constraint, ...) must not abort the whole batch any more than a
                // single failing resolver does - every cell in this column degrades
                // to an error cell instead.
                report($exception);

                foreach ($tokensByRecordKey as $rawToken) {
                    $cells[$rawToken] = $this->buildErrorCell($column, $exception);
                }

                continue;
            }

            foreach ($records as $record) {
                $recordKey = $table->getRecordKey($record);
                $rawToken = $tokensByRecordKey[$recordKey] ?? null;

                if ($rawToken === null) {
                    continue;
                }

                $cells[$rawToken] = $this->resolveCell($column, $record, $recordKey);
            }
        }

        return ['cells' => $cells];
    }

    /**
     * Builds the query cell hydration reads from, scoped exactly the way Filament
     * scopes a single-record lookup - filters applied, search deliberately NOT
     * applied (a search term in flight must not make an in-flight cell disappear).
     *
     * Filament\Tables\Concerns\HasFilters::applyFiltersToTableQuery() (verified
     * against filament/tables 5.7.5) is the exact method Filament's own canonical
     * per-record resolution, HasRecords::resolveTableRecord(), uses for this. It is
     * `protected` on the Livewire table component and not part of the public
     * HasTable contract this class is handed, so it is reached via spatie/invade -
     * a direct dependency of this package (see composer.json) rather than the
     * transitive copy filament/support happens to pull in - instead of
     * reimplementing filter application ourselves and risking silent drift from
     * Filament's own behaviour.
     *
     * Because that call reaches a protected internal Filament may rename or
     * re-signature in any release, this is guarded: if the internal is gone, fall
     * back to the public filterTableQuery() (stricter - it also applies search,
     * which is safe here even though it isn't what we want); if that is gone too,
     * fall back to the unscoped query and report() a RuntimeException so the gap
     * surfaces in error tracking instead of silently reintroducing "a forged token
     * bypasses table filters". Never throw: one Filament upgrade should not take
     * down every table using this column.
     *
     * @return Builder<Model>|Relation<Model, Model, mixed>|null
     */
    protected function scopedQuery(HasTable $component, Table $table): Builder|Relation|null
    {
        $query = $table->getQuery(isResolvingRecord: true);

        if ($query === null) {
            return null;
        }

        if (method_exists($component, 'applyFiltersToTableQuery')) {
            return invade($component)->applyFiltersToTableQuery($query, isResolvingRecord: true);
        }

        if (method_exists($component, 'filterTableQuery')) {
            return $component->filterTableQuery($query);
        }

        report(new RuntimeException(
            'AsyncColumn\'s BatchResolver could not find Filament\'s applyFiltersToTableQuery() '
            .'or filterTableQuery() method on ['.$component::class.']. Table filters will NOT be '
            .'applied when resolving async cells until this is fixed - Filament appears to have '
            .'changed an internal API this package depends on.'
        ));

        return $query;
    }

    /**
     * @return array{html: string, error: bool}
     */
    protected function resolveCell(AsyncColumn $column, Model $record, string $recordKey): array
    {
        // A fresh column instance per cell: configuration (formatters, resolver,
        // view, ...) is shared, but record, state and cached state must not bleed
        // between rows sharing the same underlying column definition.
        //
        // $recordKey is $table->getRecordKey($record) - the caller's already-
        // computed value, the SAME one the client minted its token from
        // (Filament\Tables\Contracts\HasTable::getTableRecordKey(), overridable
        // per component) - deliberately not (string) $record->getKey() here.
        // A component overriding getTableRecordKey() would otherwise leave this
        // cell's own recordKey() (visible to a developer's custom ->view() via
        // $getRecordKey(), e.g. to build its own mountTableAction(...) call) and
        // the cache key below out of sync with the identity the rest of the
        // table - and the client - use for this exact row.
        $cell = clone $column;
        $cell->record($record);
        $cell->recordKey($recordKey);
        $cell->clearCachedState();

        try {
            $state = $this->resolveState($cell, $record, $recordKey);

            return [
                'html' => $cell->renderResolvedHtml($record, $state),
                'error' => false,
            ];
        } catch (Throwable $exception) {
            // Report through Laravel's exception handler so ignored-exception
            // config, Sentry, etc. behave exactly as they would anywhere else.
            report($exception);

            return $this->buildErrorCell($cell, $exception);
        }
    }

    /**
     * @return array{html: string, error: bool}
     */
    protected function buildErrorCell(AsyncColumn $column, Throwable $exception): array
    {
        // The exception is only ever exposed to an explicit developer callback
        // (errorStateUsing()) via getErrorState(). Never interpolate $exception
        // or its message directly here - resolvers hit APIs with credentials in
        // URLs and databases that echo SQL back in their error messages.
        //
        // getErrorState() itself can throw: it evaluate()s that developer
        // closure, which may reference an unbound record (the hydration-failure
        // call site passes a column with no ->record() ever set, so a
        // Post-typed $record parameter gets a literal null and PHP throws a
        // TypeError) or may simply fail on its own (e.g. it calls the same
        // service the resolver just failed against). Both call sites are
        // inside a catch block, so an uncaught throw here would propagate out
        // of resolve() and take down the entire batch - exactly the
        // "one failure never breaks the batch" guarantee this class exists to
        // provide. Degrade to the default translated message instead.
        try {
            $errorState = $column->getErrorState($exception);
        } catch (Throwable $errorStateException) {
            report($errorStateException);

            $errorState = __('async-column::async-column.error');
        }

        return [
            'html' => $errorState instanceof Htmlable
                ? $errorState->toHtml()
                : e((string) $errorState),
            'error' => true,
        ];
    }

    protected function resolveState(AsyncColumn $column, Model $record, string $recordKey): mixed
    {
        $resolver = $column->getResolveUsing();

        if ($resolver === null) {
            throw new LogicException(
                'AsyncColumn ['.$column->getName().'] has no resolver. Call resolveUsing() when defining the column.'
            );
        }

        $ttl = $column->getCacheTtl();

        if ($ttl === null) {
            return $column->evaluate($resolver, ['record' => $record]);
        }

        return $this->rememberAllowingNull(
            $column->getCacheKey($recordKey),
            $ttl,
            fn (): mixed => $column->evaluate($resolver, ['record' => $record]),
        );
    }

    /**
     * A cache-aware stand-in for Repository::remember() that correctly round-trips
     * a legitimately null resolved value.
     *
     * Illuminate\Cache\Repository::get() collapses "nothing is cached under this
     * key" and "the cached value genuinely IS null" into the same bare PHP `null`
     * (its `is_null($value)` check can't tell a Store miss from a stored null -
     * every built-in Store's get() returns plain `null` for both). Repository::
     * remember() is built directly on that check, so it would treat every
     * previously-null resolution as a permanent miss and silently re-run the
     * resolver on every single batch despite cacheFor() - defeating caching for
     * exactly the resolvers most likely to legitimately return null (a nullable
     * API field, "no data available"). Verified directly against this project's
     * vendor/laravel/framework Repository + ArrayStore, not assumed from the
     * docblock.
     *
     * Wrapping the resolved value in a single-key array before writing sidesteps
     * this: the thing actually persisted is never itself null (even when the
     * resolver's result is), so a genuine hit is always a non-null array and a
     * true cache miss is the only thing left that reads back as bare null.
     */
    protected function rememberAllowingNull(string $key, int|CarbonInterface $ttl, Closure $callback): mixed
    {
        $store = $this->cache();

        $cached = $store->get($key);

        if ($cached !== null) {
            return $cached['value'];
        }

        // The exception propagates out of $callback() before put() runs, so a
        // failed resolution is never cached.
        $value = $callback();

        $store->put($key, ['value' => $value], $ttl);

        return $value;
    }

    protected function cache(): Repository
    {
        return Cache::store(config('async-column.cache_store'));
    }
}
