<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
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
