<?php

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Resolution\BatchResolver;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @param  iterable<int, Post>  $posts
 * @return array<int, string>
 */
function tokensFor(string $column, iterable $posts): array
{
    $tokens = [];

    foreach ($posts as $post) {
        $tokens[] = (new CellToken($column, (string) $post->getKey()))->encode();
    }

    return $tokens;
}

it('resolves every token in a single record query', function (): void {
    $posts = collect(range(1, 5))->map(fn (int $i) => Post::create(['title' => "Post {$i}"]));

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (Post $record): string => 'v-'.$record->getKey()),
    ];

    $component = mountPostsTable();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', $posts));

    expect($result['cells'])->toHaveCount(5)
        ->and($queries)->toBe(1);
});

it('isolates a failing cell without affecting its siblings', function (): void {
    $ok = Post::create(['title' => 'Fine']);
    $bad = Post::create(['title' => 'Boom']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(function (Post $record): string {
            if ($record->title === 'Boom') {
                throw new RuntimeException('upstream exploded');
            }

            return 'fine';
        }),
    ];

    $component = mountPostsTable();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', [$ok, $bad]));

    $okToken = (new CellToken('slow', (string) $ok->getKey()))->encode();
    $badToken = (new CellToken('slow', (string) $bad->getKey()))->encode();

    expect($result['cells'][$okToken]['error'])->toBeFalse()
        ->and($result['cells'][$okToken]['html'])->toContain('fine')
        ->and($result['cells'][$badToken]['error'])->toBeTrue();
});

it('never leaks the exception message by default', function (): void {
    $bad = Post::create(['title' => 'Boom']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(function (): string {
            throw new RuntimeException('secret-api-key-12345');
        }),
    ];

    $component = mountPostsTable();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', [$bad]));

    // Assert a cell was actually produced (and is clean) - not merely that an
    // empty payload happens not to contain the secret.
    expect($result['cells'])->toHaveCount(1)
        ->and(json_encode($result))->not->toContain('secret-api-key-12345');
});

it('refuses tokens for records outside the table query', function (): void {
    $visible = Post::create(['title' => 'Visible', 'author_id' => 1]);
    $hidden = Post::create(['title' => 'Hidden', 'author_id' => 999]);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (Post $record): string => 'leaked-'.$record->title),
    ];

    // Scope the table so author 999 is invisible.
    PostsTable::$scope = fn ($query) => $query->where('author_id', 1);

    $component = mountPostsTable();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', [$visible, $hidden]));

    expect($result['cells'])->toHaveCount(1)
        ->and(json_encode($result))->not->toContain('leaked-Hidden');
});

it('discards malformed and unknown-column tokens', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    $component = mountPostsTable();

    $result = app(BatchResolver::class)->resolve($component, [
        'not-a-token!!',
        (new CellToken('does_not_exist', '1'))->encode(),
    ]);

    expect($result['cells'])->toBeEmpty();
});

it('ignores a token for a column that exists but is not an AsyncColumn', function (): void {
    $post = Post::create(['title' => 'One']);

    PostsTable::$columns = [
        TextColumn::make('title'),
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    $component = mountPostsTable();

    $titleToken = (new CellToken('title', (string) $post->getKey()))->encode();
    $slowToken = (new CellToken('slow', (string) $post->getKey()))->encode();

    $result = app(BatchResolver::class)->resolve($component, [$titleToken, $slowToken]);

    // Not just "one cell survived" - specifically the AsyncColumn's cell, not
    // the plain TextColumn's.
    expect($result['cells'])->toHaveKey($slowToken)
        ->and($result['cells'])->not->toHaveKey($titleToken)
        ->and($result['cells'])->toHaveCount(1);
});

it('refuses a token for a column that is hidden or toggled off', function (): void {
    $post = Post::create(['title' => 'One']);

    $visibleFalseCalls = 0;
    $hiddenCalls = 0;
    $toggledOffCalls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('secret')
            ->visible(false)
            ->resolveUsing(function () use (&$visibleFalseCalls): string {
                $visibleFalseCalls++;

                return 'v';
            }),
        AsyncColumn::make('hush')
            ->hidden()
            ->resolveUsing(function () use (&$hiddenCalls): string {
                $hiddenCalls++;

                return 'v';
            }),
        AsyncColumn::make('toggled')
            ->toggleable()
            ->resolveUsing(function () use (&$toggledOffCalls): string {
                $toggledOffCalls++;

                return 'v';
            }),
    ];

    $component = mountPostsTable();

    // Toggle the third column off through the same public API the column
    // manager UI uses, so the test exercises real toggle state rather than a
    // hand-rolled stand-in for it.
    $state = $component->getDefaultTableColumnState();
    $state[2]['isToggled'] = false;
    $component->applyTableColumnManager($state);

    $tokens = [
        (new CellToken('secret', (string) $post->getKey()))->encode(),
        (new CellToken('hush', (string) $post->getKey()))->encode(),
        (new CellToken('toggled', (string) $post->getKey()))->encode(),
    ];

    $result = app(BatchResolver::class)->resolve($component, $tokens);

    expect($result['cells'])->toBeEmpty()
        ->and($visibleFalseCalls)->toBe(0)
        ->and($hiddenCalls)->toBe(0)
        ->and($toggledOffCalls)->toBe(0);
});

it('truncates an over-cap batch to the configured max_batch_size', function (): void {
    config(['async-column.max_batch_size' => 2]);

    $posts = collect(range(1, 5))->map(fn (int $i) => Post::create(['title' => "Post {$i}"]));

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (Post $record): string => 'v-'.$record->getKey()),
    ];

    $component = mountPostsTable();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', $posts));

    expect($result['cells'])->toHaveCount(2);
});

/**
 * Regression: `if ($maxBatchSize > 0) { array_slice(...) }` treats a
 * max_batch_size of 0 as "skip truncation" instead of "invalid, use the
 * default" - so a malformed env value (ASYNC_COLUMN_MAX_BATCH_SIZE=notanumber
 * casts to 0 via (int)) silently DISABLES the cap it was supposed to enforce.
 * 205 tokens (over the package's documented default of 200) proves whether
 * the cap actually held: an unbounded batch would resolve all 205.
 */
it('falls back to the default max_batch_size instead of disabling the cap when it is 0', function (): void {
    config(['async-column.max_batch_size' => 0]);

    $posts = collect(range(1, 205))->map(fn (int $i) => Post::create(['title' => "Post {$i}"]));

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (Post $record): string => 'v-'.$record->getKey()),
    ];

    $component = mountPostsTable();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', $posts));

    expect($result['cells'])->toHaveCount(200);
});

it('degrades a column to error cells when hydration itself fails, instead of throwing', function (): void {
    $post = Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    $component = mountPostsTable();

    // Break the table's own query only AFTER the component has already mounted
    // and rendered successfully, so it's specifically the resolver's hydration
    // call that fails - not table setup.
    Schema::drop('posts');

    $token = (new CellToken('slow', (string) $post->getKey()))->encode();

    $result = app(BatchResolver::class)->resolve($component, [$token]);

    expect($result['cells'][$token]['error'])->toBeTrue()
        ->and(json_encode($result))->not->toContain('no such table');
});

/**
 * Regression: buildErrorCell() calls $column->getErrorState($exception), which
 * evaluate()s a developer closure - and both of buildErrorCell()'s call sites
 * are inside catch blocks, so anything IT throws propagates out of resolve()
 * and kills the whole batch, contradicting "one failure never breaks the
 * batch".
 *
 * This is the hydration-failure call site specifically: the $column passed to
 * buildErrorCell() there is the table's own live, shared column instance
 * (never cloned - cloning only happens per-cell, in resolveCell(), after
 * hydration succeeds). HasRecord::record() is a plain mutable property that is
 * never reset, so it stays null unless some earlier table render looped over
 * at least one row and called ->record() on this exact column - which is why
 * the table is mounted here with ZERO rows, before the one Post used for the
 * token is created. Filament's
 * Column::resolveDefaultClosureDependencyForEvaluationByName() then resolves a
 * `record`-named closure parameter to $this->getRecord() by NAME before ever
 * looking at its type - so a `Post $record` (non-nullable) parameter receives
 * a literal null and PHP itself throws a TypeError when the closure runs.
 */
it('does not let a throwing errorStateUsing() during a hydration failure escape resolve()', function (): void {
    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->resolveUsing(fn (): string => 'v')
            ->errorStateUsing(fn (Post $record): string => 'never reached: '.$record->getKey()),
    ];

    // Mounted while the table is empty, so the column is never record()-bound
    // by an initial listing render.
    $component = mountPostsTable();

    $post = Post::create(['title' => 'One']);

    Schema::drop('posts');

    $token = (new CellToken('slow', (string) $post->getKey()))->encode();

    $result = app(BatchResolver::class)->resolve($component, [$token]);

    expect($result['cells'][$token]['error'])->toBeTrue()
        ->and($result['cells'][$token]['html'])->toContain('Could not load');
});

/**
 * Same guarantee, but for the per-cell call site in resolveCell(): the record
 * IS bound here, but errorStateUsing() itself throws - e.g. because it calls
 * the same failing service the resolver just called. Sibling cells in the same
 * batch must still resolve normally.
 */
it('degrades to the default error state when errorStateUsing() itself throws for a resolved record', function (): void {
    $ok = Post::create(['title' => 'Fine']);
    $bad = Post::create(['title' => 'Boom']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->resolveUsing(function (Post $record): string {
                if ($record->title === 'Boom') {
                    throw new RuntimeException('upstream exploded');
                }

                return 'fine';
            })
            ->errorStateUsing(function (): string {
                throw new RuntimeException('errorStateUsing exploded too');
            }),
    ];

    $component = mountPostsTable();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', [$ok, $bad]));

    $okToken = (new CellToken('slow', (string) $ok->getKey()))->encode();
    $badToken = (new CellToken('slow', (string) $bad->getKey()))->encode();

    expect($result['cells'][$okToken]['error'])->toBeFalse()
        ->and($result['cells'][$okToken]['html'])->toContain('fine')
        ->and($result['cells'][$badToken]['error'])->toBeTrue()
        ->and($result['cells'][$badToken]['html'])->toContain('Could not load');
});

/**
 * Canary: BatchResolver::scopedQuery() reaches Filament\Tables\Concerns\HasFilters
 * ::applyFiltersToTableQuery() via spatie/invade because it is `protected` and not
 * part of the public HasTable contract. If a Filament upgrade renames or removes
 * it, BatchResolver falls back to the public filterTableQuery() and then to an
 * unfiltered query (see scopedQuery()'s docblock) rather than throwing - which
 * means the "table filters are bypassed by a forged token" gap could silently
 * reopen without a single test failing. This test exists purely to make that
 * upgrade loud: if it fails, go update BatchResolver::scopedQuery() to match
 * whatever Filament renamed the method to, don't just delete this test.
 */
it('still finds the protected Filament method BatchResolver reaches via invade()', function (): void {
    expect(method_exists(PostsTable::class, 'applyFiltersToTableQuery'))->toBeTrue();
});

it('excludes a record hidden by an active table filter, without invoking its resolver', function (): void {
    $visible = Post::create(['title' => 'Visible', 'author_id' => 1]);
    $hidden = Post::create(['title' => 'Hidden', 'author_id' => 999]);

    $calls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(function (Post $record) use (&$calls): string {
            $calls++;

            return 'v-'.$record->getKey();
        }),
    ];

    // A real Filament filter, active by default (no user interaction needed),
    // built through the actual filter API - not a stand-in that modifies the
    // base query directly, which would prove nothing about filter handling.
    PostsTable::$filters = [
        Filter::make('onlyAuthorOne')
            ->query(fn (Builder $query): Builder => $query->where('author_id', 1))
            ->default(),
    ];

    $component = mountPostsTable();

    // Sanity: the filter genuinely excludes $hidden from the table's own listing
    // query, or this test would prove nothing about filter enforcement.
    expect($component->getTableRecords()->pluck('id'))->not->toContain($hidden->getKey());

    $visibleToken = (new CellToken('slow', (string) $visible->getKey()))->encode();
    $hiddenToken = (new CellToken('slow', (string) $hidden->getKey()))->encode();

    $result = app(BatchResolver::class)->resolve($component, [$visibleToken, $hiddenToken]);

    expect($result['cells'])->toHaveKey($visibleToken)
        ->and($result['cells'])->not->toHaveKey($hiddenToken)
        ->and($calls)->toBe(1);
});

it('does NOT apply the active search term when resolving cells, unlike filterTableQuery', function (): void {
    $searchedFor = Post::create(['title' => 'Alpha']);
    $excludedBySearch = Post::create(['title' => 'Zulu']);

    $calls = 0;

    PostsTable::$columns = [
        TextColumn::make('title')->searchable(),
        AsyncColumn::make('slow')->resolveUsing(function (Post $record) use (&$calls): string {
            $calls++;

            return 'v-'.$record->getKey();
        }),
    ];

    $component = mountPostsTable(fn ($testable) => $testable->set('tableSearch', 'Alpha'));

    // Sanity: the active search genuinely excludes $excludedBySearch from the
    // table's own listing query, or this test would prove nothing.
    expect($component->getTableRecords()->pluck('id'))->not->toContain($excludedBySearch->getKey());

    // A cell for the search-excluded record must still resolve: an in-flight
    // batch request must not have cells vanish out from under it just because
    // the user typed into the search box in the meantime.
    $token = (new CellToken('slow', (string) $excludedBySearch->getKey()))->encode();

    $result = app(BatchResolver::class)->resolve($component, [$token]);

    expect($result['cells'])->toHaveKey($token)
        ->and($calls)->toBe(1);
});

/**
 * Regression: resolveCell() bound the cloned cell's own recordKey via
 * `(string) $record->getKey()`, and resolveState() built the cache key the
 * same way - but the client mints its token from $table->getRecordKey($record)
 * (Filament\Tables\Contracts\HasTable::getTableRecordKey()), which a hosting
 * component is free to override. When it does, the raw model key and the
 * table's own record key diverge, corrupting both:
 *  - anything a developer's custom ->view() renders via $getRecordKey()
 *    (e.g. a hand-built `mountTableAction('name', '{$getRecordKey()}')` call);
 *  - the ->cacheFor() cache key, which must stay consistent with the key
 *    identity the rest of the table (and any other cache reader) uses.
 *
 * PostsTable::$recordKeyUsing lets this test swap in a zero-padded override
 * ('001' instead of '1') - still findable by whereKey() thanks to SQLite's
 * numeric-string coercion (verified directly against this project's own
 * sqlite :memory: connection), so hydration genuinely succeeds and this test
 * exercises the real resolve() path, not a stand-in for it.
 */
it("uses the table's own record key, not the raw model key, for the resolved cell and its cache key", function (): void {
    $post = Post::create(['title' => 'One']);

    PostsTable::$recordKeyUsing = fn (Model|array $record): string => str_pad(
        (string) (is_array($record) ? ($record['id'] ?? '') : $record->getKey()),
        3,
        '0',
        STR_PAD_LEFT,
    );

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->cacheFor(300)
            ->resolveUsing(fn (): string => 'v')
            ->view('async-column-tests::record-key'),
    ];

    $component = mountPostsTable();

    // The client mints its token from the table's own (overridden) record
    // key - exactly what AsyncColumn::getCellToken() does in production.
    $tableRecordKey = $component->getTableRecordKey($post);
    expect($tableRecordKey)->toBe('001');

    $token = (new CellToken('slow', $tableRecordKey))->encode();

    $result = app(BatchResolver::class)->resolve($component, [$token]);

    $column = $component->getTable()->getColumn('slow');
    expect($column)->toBeInstanceOf(AsyncColumn::class);
    assert($column instanceof AsyncColumn);

    expect($result['cells'][$token]['html'])->toContain('data-record-key="001"')
        ->and($result['cells'][$token]['html'])->not->toContain('data-record-key="1"')
        ->and(Cache::get($column->getCacheKey('001')))->not->toBeNull()
        ->and(Cache::get($column->getCacheKey((string) $post->getKey())))->toBeNull();
});
