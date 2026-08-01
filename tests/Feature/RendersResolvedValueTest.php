<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use Livewire\Livewire;

/**
 * AsyncColumn::getState() resolves its cache key via
 * $this->getLivewire()->getTableRecordKey(), so a bare AsyncColumn::make() throws
 * "not mounted to a table." Mount through PostsTable instead — the same path the
 * production BatchResolver (Task 5) uses via $table->getColumn($name) — so these
 * tests exercise the real code, not a standalone column that can never occur in
 * practice.
 */
function mountColumn(AsyncColumn $column): AsyncColumn
{
    PostsTable::$columns = [$column];

    $table = Livewire::test(PostsTable::class)->instance()->getTable();

    return $table->getColumn($column->getName());
}

it('renders a plain string, escaped', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow'));

    $html = $column->renderResolvedHtml($post, '<b>hi</b>');

    expect($html)->toContain('&lt;b&gt;hi&lt;/b&gt;')
        ->and($html)->not->toContain('<b>hi</b>');
});

it('renders raw HTML when html() is set', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->html());

    $html = $column->renderResolvedHtml($post, '<b>hi</b>');

    expect($html)->toContain('<b>hi</b>');
});

it('applies formatStateUsing', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(
        AsyncColumn::make('slow')->formatStateUsing(fn (string $state): string => strtoupper($state))
    );

    $html = $column->renderResolvedHtml($post, 'shipped');

    expect($html)->toContain('SHIPPED');
});

it('renders a view with state and record available', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->view('async-column-tests::badge'));

    $html = $column->renderResolvedHtml($post, 'shipped');

    expect($html)->toContain('shipped')
        ->and($html)->toContain('One');
});

/**
 * Confirms the earlier hand-rolled-vs-composed-trait finding: money()'s internal
 * closure type-hints `TextColumn $column`, which threw a TypeError when AsyncColumn
 * only composed the CanFormatState trait without being a TextColumn. Now that
 * AsyncColumn extends TextColumn directly, $this genuinely satisfies that type hint
 * and the real Number::currency() formatting runs.
 */
it('supports Filament money formatting on resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->money('USD'));

    $html = $column->renderResolvedHtml($post, 1234.5);

    expect($html)->toBe('$1,234.50');
});

it('supports Filament date formatting on resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->date('Y-m-d'));

    $html = $column->renderResolvedHtml($post, '2026-08-01 12:34:00');

    expect($html)->toBe('2026-08-01');
});

it('supports Filament markdown formatting on resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->markdown());

    $html = $column->renderResolvedHtml($post, '**bold**');

    expect($html)->toContain('<strong>bold</strong>');
});

it('supports Filament character limiting on resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->limit(5));

    $html = $column->renderResolvedHtml($post, 'abcdefghij');

    expect($html)->toBe('abcde...');
});

it('supports prefix and suffix on resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->prefix('[')->suffix(']'));

    $html = $column->renderResolvedHtml($post, 'x');

    expect($html)->toContain('[x]');
});
