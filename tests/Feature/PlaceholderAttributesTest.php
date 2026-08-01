<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

it('marks viewport-triggered columns', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v')->whenVisible(),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('data-async-column-when-visible="1"');
});

it('marks retryable columns by default', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('data-async-column-retryable="1"');
});

it('omits the retry hook when retry is disabled', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v')->retryable(false),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->not->toContain('data-async-column-retryable');
});

/**
 * The client-side markFailed()/renderFailed() JS (async-column.js) needs the
 * translated error/retry text to make a failed cell's content visible instead
 * of hardcoding English into the JS — this is the server-rendered contract it
 * reads via el.dataset, so it's tested here rather than in JS (this project
 * deliberately has no JS test runner).
 */
it('always carries the translated error label, for the client-side failure fallback', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('data-async-column-error-label="Could not load"');
});

it('carries the translated retry label only when retryable', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v')->retryable(false),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->not->toContain('data-async-column-retry-label');
});

it('renders the translated retry label when retryable (the default)', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('data-async-column-retry-label="Retry"');
});

/**
 * Safety net for the M11 simplification of the placeholder view (dropping the
 * `$loadingState instanceof Htmlable ? new HtmlString(...) : $loadingState`
 * ternary down to a bare `{{ $loadingState }}`): Blade's e() helper already
 * calls ->toHtml() unescaped for anything Htmlable (verified against
 * vendor/laravel/framework's e() and BladeCompiler's default 'e(%s)' echo
 * format), so both forms must produce byte-identical output for an Htmlable
 * loading state.
 */
it('renders an Htmlable loading state unescaped', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->resolveUsing(fn (): string => 'v')
            ->loadingState(new HtmlString('<span class="custom-loading">Loading…</span>')),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('<span class="custom-loading">Loading…</span>');
});
