<?php

namespace GiacomoMasseroni\AsyncColumn\Resolution;

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
 *    scopedQuery() below) — never Model::find(). Tenancy, global scopes, table
 *    filters and any ->modifyQueryUsing() constraints therefore apply. A key for
 *    a row outside that query simply never comes back from ->get(), so no cell
 *    is produced for it.
 *  - Only columns that are actually defined on the table (and are AsyncColumn
 *    instances) are resolved. An unknown or mistyped column name is silently
 *    dropped rather than turned into an error that could leak table structure.
 *  - A column that exists but is currently hidden ($column->isVisible() === false)
 *    or toggled off by the user ($column->isToggledHidden() === true) is rejected
 *    the same way. getColumn() returns every registered column regardless of
 *    visibility, so without this check a forged token could reveal a value the
 *    table itself never rendered a placeholder for.
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
     * @param  array<int, mixed>  $tokens
     * @return array{cells: array<string, array{html: string, error: bool}>}
     */
    public function resolve(HasTable $component, array $tokens): array
    {
        $table = $component->getTable();

        $maxBatchSize = (int) config('async-column.max_batch_size', 200);

        if ($maxBatchSize > 0) {
            $tokens = array_slice($tokens, 0, $maxBatchSize);
        }

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

            $column = $table->getColumn($token->columnName);

            // Only columns actually defined on this table are resolvable. A forged
            // column name simply produces nothing — never an error, never a
            // callable built from client input.
            if (! $column instanceof AsyncColumn) {
                continue;
            }

            // getColumn() returns every registered column regardless of whether
            // it is currently visible or has been toggled off by the user. A
            // hidden or toggled-off column never rendered a placeholder in the
            // first place, so a forged token for it must produce nothing too.
            if ((! $column->isVisible()) || $column->isToggledHidden()) {
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
                // record key cannot reach a row the user could not already see — it
                // simply never comes back here.
                $records = $query->whereKey(array_keys($tokensByRecordKey))->get();
            } catch (Throwable $exception) {
                // A query-level failure (bad connection, broken filter, broken
                // constraint, ...) must not abort the whole batch any more than a
                // single failing resolver does — every cell in this column degrades
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

                $cells[$rawToken] = $this->resolveCell($column, $record);
            }
        }

        return ['cells' => $cells];
    }

    /**
     * Builds the query cell hydration reads from, scoped exactly the way Filament
     * scopes a single-record lookup — filters applied, search deliberately NOT
     * applied (a search term in flight must not make an in-flight cell disappear).
     *
     * Filament\Tables\Concerns\HasFilters::applyFiltersToTableQuery() (verified
     * against filament/tables 5.7.5) is the exact method Filament's own canonical
     * per-record resolution, HasRecords::resolveTableRecord(), uses for this. It is
     * `protected` on the Livewire table component and not part of the public
     * HasTable contract this class is handed, so it is reached via spatie/invade —
     * a direct dependency of this package (see composer.json) rather than the
     * transitive copy filament/support happens to pull in — instead of
     * reimplementing filter application ourselves and risking silent drift from
     * Filament's own behaviour.
     *
     * Because that call reaches a protected internal Filament may rename or
     * re-signature in any release, this is guarded: if the internal is gone, fall
     * back to the public filterTableQuery() (stricter — it also applies search,
     * which is safe here even though it isn't what we want); if that is gone too,
     * fall back to the unscoped query and report() a RuntimeException so the gap
     * surfaces in error tracking instead of silently reintroducing "a forged token
     * bypasses table filters". Never throw: one Filament upgrade should not take
     * down every table using this column.
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
            .'applied when resolving async cells until this is fixed — Filament appears to have '
            .'changed an internal API this package depends on.'
        ));

        return $query;
    }

    /**
     * @return array{html: string, error: bool}
     */
    protected function resolveCell(AsyncColumn $column, Model $record): array
    {
        // A fresh column instance per cell: configuration (formatters, resolver,
        // view, ...) is shared, but record, state and cached state must not bleed
        // between rows sharing the same underlying column definition.
        $cell = clone $column;
        $cell->record($record);
        $cell->recordKey((string) $record->getKey());
        $cell->clearCachedState();

        try {
            $state = $this->resolveState($cell, $record);

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
        // or its message directly here — resolvers hit APIs with credentials in
        // URLs and databases that echo SQL back in their error messages.
        $errorState = $column->getErrorState($exception);

        return [
            'html' => $errorState instanceof Htmlable
                ? $errorState->toHtml()
                : e((string) $errorState),
            'error' => true,
        ];
    }

    protected function resolveState(AsyncColumn $column, Model $record): mixed
    {
        $resolver = $column->getResolveUsing();

        if ($resolver === null) {
            throw new LogicException(
                'AsyncColumn ['.$column->getName().'] has no resolver. Call resolveUsing() when defining the column.'
            );
        }

        return $column->evaluate($resolver, ['record' => $record]);
    }
}
