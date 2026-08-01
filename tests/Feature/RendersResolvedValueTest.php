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
