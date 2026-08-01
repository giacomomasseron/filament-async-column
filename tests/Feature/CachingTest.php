<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Resolution\BatchResolver;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use Livewire\Livewire;

it('does not cache by default', function (): void {
    $post = Post::create(['title' => 'One']);
    $calls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(function () use (&$calls): string {
            $calls++;

            return 'v';
        }),
    ];

    $component = Livewire::test(PostsTable::class)->instance();
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

    $component = Livewire::test(PostsTable::class)->instance();
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
    // nested closure declares `use (&$calls)` for — so the nested closure would
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
    app(BatchResolver::class)->resolve(Livewire::test(PostsTable::class)->instance(), $tokens);

    PostsTable::$columns = [$makeColumn('b')];
    app(BatchResolver::class)->resolve(Livewire::test(PostsTable::class)->instance(), $tokens);

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

    $component = Livewire::test(PostsTable::class)->instance();
    $tokens = [(new CellToken('slow', (string) $post->getKey()))->encode()];

    app(BatchResolver::class)->resolve($component, $tokens);
    app(BatchResolver::class)->resolve($component, $tokens);

    expect($calls)->toBe(2);
});
