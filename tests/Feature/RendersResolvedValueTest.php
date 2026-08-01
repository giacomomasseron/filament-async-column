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
 * CanFormatState::money() — like date(), dateTime(), since(), numeric(), time(), and
 * their *Tooltip() siblings — registers its formatting closure with a hard-coded
 * `TextColumn $column` parameter (see vendor/filament/tables/src/Columns/Concerns/
 * CanFormatState.php). AsyncColumn is not a TextColumn, so Filament's own evaluator
 * passes `$this` for that parameter and PHP's type check rejects it. This is a real,
 * verified incompatibility in composing CanFormatState onto a non-TextColumn class —
 * not a missing ext-intl/config issue — so it is pinned here rather than silently
 * dropped. money()/date()/dateTime()/since()/numeric()/time()/isoDate()/isoDateTime()/
 * isoTime() and the *Tooltip() variants are unsupported on AsyncColumn for this reason.
 */
it('documents that money() is unusable on AsyncColumn (TextColumn-typed closure parameter)', function (): void {
    $post = Post::create(['title' => 'One']);

    $column = mountColumn(AsyncColumn::make('slow')->money('USD'));

    expect(fn () => $column->renderResolvedHtml($post, 1234.5))
        ->toThrow(TypeError::class, 'must be of type Filament\Tables\Columns\TextColumn');
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
