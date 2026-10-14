<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Enums\Loader;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use Livewire\Livewire;

/**
 * Backward-compatibility guard: a column that never calls loader() or
 * loaderColor() must keep rendering the exact skeleton markup it always has,
 * so theme overrides targeting .fi-async-column-skeleton keep applying.
 */
it('keeps the original skeleton markup when no loader is configured', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('<span class="fi-async-column-skeleton" aria-hidden="true"></span>');
});

it('renders the chosen loader preset', function (Loader|string $loader, string $markup): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v')->loader($loader),
    ];

    expect(Livewire::test(PostsTable::class)->html())->toContain($markup);
})->with([
    'spinner (enum)' => [Loader::Spinner, '<span class="fi-async-column-spinner" aria-hidden="true"></span>'],
    'bar (string)' => ['bar', '<span class="fi-async-column-bar" aria-hidden="true"></span>'],
    'dots (enum)' => [Loader::Dots, '<span class="fi-async-column-dots" aria-hidden="true"><span></span><span></span><span></span></span>'],
]);

it('colors the loader from the Filament palette', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->resolveUsing(fn (): string => 'v')
            ->loader(Loader::Spinner)
            ->loaderColor('primary'),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('<span class="fi-async-column-spinner fi-color fi-color-primary" aria-hidden="true"></span>');
});

it('colors the default skeleton when only a color is set', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v')->loaderColor('success'),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('<span class="fi-async-column-skeleton fi-color fi-color-success" aria-hidden="true"></span>');
});

/**
 * The color name is concatenated into raw HTML, so anything beyond a class-safe
 * name must never reach the page - the same sanitisation Filament's own
 * ColorManager::getComponentClasses() applies.
 */
it('strips the color down to a class-safe name', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->resolveUsing(fn (): string => 'v')
            ->loaderColor('primary" onmouseover="alert(1)'),
    ];

    $html = Livewire::test(PostsTable::class)->html();

    expect($html)->toContain('class="fi-async-column-skeleton fi-color fi-color-primaryonmouseoveralert1"')
        ->and($html)->not->toContain('onmouseover="');
});

it('evaluates the loader and color closures per record', function (): void {
    Post::create(['title' => 'Urgent']);
    Post::create(['title' => 'Calm']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->resolveUsing(fn (): string => 'v')
            ->loader(fn (Post $record): Loader => $record->title === 'Urgent' ? Loader::Spinner : Loader::Dots)
            ->loaderColor(fn (Post $record): ?string => $record->title === 'Urgent' ? 'danger' : null),
    ];

    expect(Livewire::test(PostsTable::class)->html())
        ->toContain('<span class="fi-async-column-spinner fi-color fi-color-danger" aria-hidden="true"></span>')
        ->toContain('<span class="fi-async-column-dots" aria-hidden="true"><span></span><span></span><span></span></span>');
});

it('prefers an explicit loading state over the loader preset', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->resolveUsing(fn (): string => 'v')
            ->loader(Loader::Spinner)
            ->loadingState('Fetching…'),
    ];

    $html = Livewire::test(PostsTable::class)->html();

    expect($html)->toContain('Fetching…')
        ->and($html)->not->toContain('fi-async-column-spinner');
});

it('rejects an unknown loader name, listing the valid ones', function (): void {
    AsyncColumn::make('slow')->loader('spiner')->getLoader();
})->throws(InvalidArgumentException::class, 'no loader named [spiner]. Use one of: skeleton, spinner, dots, bar.');
