<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Resolution\BatchResolver;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;

it('does not cache by default', function (): void {
    $post = Post::create(['title' => 'One']);
    $calls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(function () use (&$calls): string {
            $calls++;

            return 'v';
        }),
    ];

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(2);
});

it('reuses a cached value within the ttl', function (): void {
    $post = Post::create(['title' => 'One']);
    $calls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->cacheFor(300)
            ->resolveUsing(function () use (&$calls): string {
                $calls++;

                return 'v';
            }),
    ];

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    $result = app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(1)
        ->and($result['cells'][$tokens[0]]['html'])->toContain('v');
});

it('busts the cache when the version changes', function (): void {
    $post = Post::create(['title' => 'One']);
    $calls = 0;

    // A regular closure, not an arrow function: an arrow function auto-captures
    // every variable referenced anywhere in its body BY VALUE, including ones a
    // nested closure declares `use (&$calls)` for - so the nested closure would
    // bind to the arrow function's own by-value copy of $calls on each call,
    // never the outer $calls this test asserts on. Explicit `use (&$calls)` here
    // avoids that trap.
    $makeColumn = function (string $version) use (&$calls): AsyncColumn {
        return AsyncColumn::make('slow')
            ->cacheFor(300)
            ->cacheVersion($version)
            ->resolveUsing(function () use (&$calls): string {
                $calls++;

                return 'v';
            });
    };

    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    PostsTable::$columns = [$makeColumn('a')];
    app(BatchResolver::class)->resolve(mountPostsTable(), $tokens);

    PostsTable::$columns = [$makeColumn('b')];
    app(BatchResolver::class)->resolve(mountPostsTable(), $tokens);

    expect($calls)->toBe(2);
});

it('does not cache a failed resolution', function (): void {
    $post = Post::create(['title' => 'One']);
    $calls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->cacheFor(300)
            ->resolveUsing(function () use (&$calls): string {
                $calls++;

                throw new RuntimeException('nope');
            }),
    ];

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(2);
});

it('caches a legitimately null resolved value instead of re-running the resolver', function (): void {
    // Illuminate\Cache\Repository::get()'s is_null() check cannot distinguish
    // "nothing cached under this key" from "the cached value genuinely IS
    // null" -- both collapse to bare PHP null at the Store layer. A resolver
    // whose correct result is null (a nullable API field, "no data available")
    // must still only run once per TTL window, not on every single batch.
    $post = Post::create(['title' => 'One']);
    $calls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->cacheFor(300)
            ->resolveUsing(function () use (&$calls) {
                $calls++;

                return null;
            }),
    ];

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    $first = app(BatchResolver::class)->resolve($component, $tokens);
    $second = app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(1)
        ->and($first['cells'])->toHaveKey($tokens[0])
        ->and($second['cells'])->toHaveKey($tokens[0])
        ->and($second['cells'][$tokens[0]]['error'])->toBeFalse();
});

it('never actually persists a value when cacheFor(0) is used, so the resolver runs on every batch', function (): void {
    // Documents cacheFor(0)'s real behavior rather than leaving it implicit:
    // Illuminate\Cache\Repository::put() treats a computed TTL of <= 0 seconds
    // as a forget() instead of a write (see Repository::put()), so a TTL of 0
    // takes the caching code path on every call but never actually leaves
    // anything behind -- functionally identical to caching being off, just
    // with the extra get()/forget() round-trip on every resolution.
    $post = Post::create(['title' => 'One']);
    $calls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->cacheFor(0)
            ->resolveUsing(function () use (&$calls): string {
                $calls++;

                return 'v';
            }),
    ];

    $component = mountPostsTable();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(2);
});
