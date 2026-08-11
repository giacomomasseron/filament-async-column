<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Resolution\BatchResolver;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;

/**
 * Defines a counting resolver on the fixture table and returns the counter by
 * reference, so every test here can assert on "did the resolver actually run
 * again?" - the only observable proof a key was really removed - rather than
 * reaching into the cache store and asserting on the key format, which would
 * pass just as happily against a forget() that builds the wrong key.
 */
function countingColumn(?string $version = null): Closure
{
    return function (int &$calls) use ($version): void {
        $column = AsyncColumn::make('slow')
            ->cacheFor(300)
            ->resolveUsing(function () use (&$calls): string {
                $calls++;

                return 'v';
            });

        if ($version !== null) {
            $column->cacheVersion($version);
        }

        PostsTable::$columns = [$column];
    };
}

it('makes the resolver run again after a cached cell is forgotten', function (): void {
    $post = Post::create(['title' => 'One']);
    $calls = 0;
    countingColumn()($calls);

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    app(BatchResolver::class)->resolve($component, $tokens);
    expect($calls)->toBe(1);

    AsyncColumn::forget(PostsTable::class, 'slow', $post->getKey());

    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(2);
});

it('forgets every record key given an array', function (): void {
    $one = Post::create(['title' => 'One']);
    $two = Post::create(['title' => 'Two']);
    $calls = 0;
    countingColumn()($calls);

    $component = mountPostsTable();
    $tokens = [
        (new CellToken('slow', (string) $one->getKey()))->encode(),
        (new CellToken('slow', (string) $two->getKey()))->encode(),
    ];

    app(BatchResolver::class)->resolve($component, $tokens);
    expect($calls)->toBe(2);

    AsyncColumn::forget(PostsTable::class, 'slow', [$one->getKey(), $two->getKey()]);

    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(4);
});

it('forgets a versioned cell when the matching version is supplied', function (): void {
    $post = Post::create(['title' => 'One']);
    $calls = 0;
    countingColumn('v2')($calls);

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    expect($calls)->toBe(1);

    AsyncColumn::forget(PostsTable::class, 'slow', $post->getKey(), version: 'v2');

    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(2);
});

it('leaves a versioned cell alone when no version is supplied', function (): void {
    // Proves the version segment is genuinely part of the key rather than
    // decoration: a versionless forget() must miss a versioned cell, not
    // accidentally match it.
    $post = Post::create(['title' => 'One']);
    $calls = 0;
    countingColumn('v2')($calls);

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    expect($calls)->toBe(1);

    AsyncColumn::forget(PostsTable::class, 'slow', $post->getKey());

    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(1);
});

it('forgets from the configured cache store rather than the default one', function (): void {
    // A forget() that reached for the application default store would leave the
    // value sitting in 'secondary' and the resolver would never run again - so
    // the call count is what proves the store lookup, without asserting on any
    // internal key.
    config()->set('cache.stores.secondary', ['driver' => 'array']);
    config()->set('async-column.cache_store', 'secondary');

    $post = Post::create(['title' => 'One']);
    $calls = 0;
    countingColumn()($calls);

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    expect($calls)->toBe(1);

    AsyncColumn::forget(PostsTable::class, 'slow', $post->getKey());

    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(2);
});

it('rejects a closure version instead of silently forgetting the wrong key', function (): void {
    // There is no column instance and no $record to evaluate a closure against
    // here, so accepting one would stringify to something meaningless and
    // remove a key nothing ever wrote - a silent no-op the caller would read as
    // a successful invalidation.
    AsyncColumn::forget(PostsTable::class, 'slow', 1, version: fn (): string => 'v2');
})->throws(LogicException::class, 'cannot accept a Closure');

it('is a silent no-op for a cell that was never cached', function (): void {
    AsyncColumn::forget(PostsTable::class, 'never-cached', 12345);
})->throwsNoExceptions();
