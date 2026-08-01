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
 *
 * renderResolvedHtml() now delegates to TextColumn::toEmbeddedHtml() instead of
 * hand-rolling formatting/escaping, so the output is wrapped in TextColumn's own
 * `<div class="fi-ta-text fi-ta-text-item fi-size-sm">...</div>` — the exact wrapper
 * every other TextColumn cell gets. These assertions use toContain() (not toBe())
 * to check the formatted value survived, not the exact wrapper markup.
 */
it('supports Filament money formatting on resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->money('USD'));

    $html = $column->renderResolvedHtml($post, 1234.5);

    expect($html)->toContain('$1,234.50');
});

it('supports Filament date formatting on resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->date('Y-m-d'));

    $html = $column->renderResolvedHtml($post, '2026-08-01 12:34:00');

    expect($html)->toContain('2026-08-01');
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

    expect($html)->toContain('abcde...');
});

it('supports prefix and suffix on resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->prefix('[')->suffix(']'));

    $html = $column->renderResolvedHtml($post, 'x');

    expect($html)->toContain('[x]');
});

it('renders array state as a list, with no PHP warning', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow'));

    $warnings = [];
    set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;

        return true;
    });

    $html = $column->renderResolvedHtml($post, ['a', 'b']);

    restore_error_handler();

    expect($warnings)->toBeEmpty()
        ->and($html)->not->toContain('Array')
        ->and($html)->toContain('a')
        ->and($html)->toContain('b');
});

/**
 * The old hand-rolled formatter returned '' for a null state. Delegating to
 * toEmbeddedHtml() changes this: TextColumn's blank-state branch now renders an
 * (empty) `<div class="fi-ta-text">...</div>` placeholder wrapper instead. Both are
 * "no error, no visible text" — this test documents the new shape rather than
 * asserting the old empty-string behavior.
 */
it('handles a null resolved state without error', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow'));

    $html = $column->renderResolvedHtml($post, null);

    expect($html)->toContain('fi-ta-text')
        ->and($html)->not->toContain('Array');
});

it('badge() and color() now visibly apply to the resolved state', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->badge()->color('success'));

    $html = $column->renderResolvedHtml($post, 'shipped');

    expect($html)->toContain('fi-badge')
        ->and($html)->toContain('fi-color-success')
        ->and($html)->toContain('shipped');
});

/**
 * Regression for resolvedState() assigning a raw `null` to $this->getStateUsing.
 *
 * HasCellState::getState() branches on `$this->getStateUsing !== null` — a bare
 * null there does not mean "the resolved value is null", it means "there is no
 * state callback at all", so Filament falls through to getStateFromRecord() and
 * renders the record's own DB attribute instead of the (null) resolved value.
 *
 * The column is deliberately named after a REAL Post attribute ('title'), not
 * 'slow' like every other test in this file: 'slow' doesn't exist on Post, so
 * data_get() returns null for the fallback path too and the bug is invisible.
 * Naming the column 'title' makes the fallback produce a visibly different,
 * wrong value ('One') so this test can actually catch the regression.
 */
it('does not fall back to the record attribute when a resolver returns null', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('title')->resolveUsing(fn (): mixed => null));

    $html = $column->renderResolvedHtml($post, null);

    expect($html)->not->toContain('One');
});
