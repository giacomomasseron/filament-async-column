<?php

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Resolution\BatchResolver;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

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

    $component = Livewire::test(PostsTable::class)->instance();

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

    $component = Livewire::test(PostsTable::class)->instance();

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

    $component = Livewire::test(PostsTable::class)->instance();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', [$bad]));

    // Assert a cell was actually produced (and is clean) — not merely that an
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

    $component = Livewire::test(PostsTable::class)->instance();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', [$visible, $hidden]));

    expect($result['cells'])->toHaveCount(1)
        ->and(json_encode($result))->not->toContain('leaked-Hidden');
});

it('discards malformed and unknown-column tokens', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    $component = Livewire::test(PostsTable::class)->instance();

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

    $component = Livewire::test(PostsTable::class)->instance();

    $titleToken = (new CellToken('title', (string) $post->getKey()))->encode();
    $slowToken = (new CellToken('slow', (string) $post->getKey()))->encode();

    $result = app(BatchResolver::class)->resolve($component, [$titleToken, $slowToken]);

    // Not just "one cell survived" — specifically the AsyncColumn's cell, not
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

    $component = Livewire::test(PostsTable::class)->instance();

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

    $component = Livewire::test(PostsTable::class)->instance();

    $result = app(BatchResolver::class)->resolve($component, tokensFor('slow', $posts));

    expect($result['cells'])->toHaveCount(2);
});

it('degrades a column to error cells when hydration itself fails, instead of throwing', function (): void {
    $post = Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    $component = Livewire::test(PostsTable::class)->instance();

    // Break the table's own query only AFTER the component has already mounted
    // and rendered successfully, so it's specifically the resolver's hydration
    // call that fails — not table setup.
    Schema::drop('posts');

    $token = (new CellToken('slow', (string) $post->getKey()))->encode();

    $result = app(BatchResolver::class)->resolve($component, [$token]);

    expect($result['cells'][$token]['error'])->toBeTrue()
        ->and(json_encode($result))->not->toContain('no such table');
});

/**
 * Canary: BatchResolver::scopedQuery() reaches Filament\Tables\Concerns\HasFilters
 * ::applyFiltersToTableQuery() via spatie/invade because it is `protected` and not
 * part of the public HasTable contract. If a Filament upgrade renames or removes
 * it, BatchResolver falls back to the public filterTableQuery() and then to an
 * unfiltered query (see scopedQuery()'s docblock) rather than throwing — which
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
    // built through the actual filter API — not a stand-in that modifies the
    // base query directly, which would prove nothing about filter handling.
    PostsTable::$filters = [
        Filter::make('onlyAuthorOne')
            ->query(fn (Builder $query): Builder => $query->where('author_id', 1))
            ->default(),
    ];

    $component = Livewire::test(PostsTable::class)->instance();

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

    $component = Livewire::test(PostsTable::class)
        ->set('tableSearch', 'Alpha')
        ->instance();

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
