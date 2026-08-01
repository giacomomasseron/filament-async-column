<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use Livewire\Livewire;

it('never invokes the resolver during table render', function (): void {
    Post::create(['title' => 'One']);
    Post::create(['title' => 'Two']);

    $calls = 0;

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(function () use (&$calls) {
            $calls++;

            return 'resolved';
        }),
    ];

    Livewire::test(PostsTable::class)
        ->assertDontSee('resolved');

    expect($calls)->toBe(0);
});

it('emits one placeholder carrying a decodable token per row', function (): void {
    $post = Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'resolved'),
    ];

    $html = Livewire::test(PostsTable::class)->html();

    $expected = (new \GiacomoMasseroni\AsyncColumn\Resolution\CellToken('slow', (string) $post->getKey()))->encode();

    expect($html)->toContain('data-async-column-token="'.$expected.'"');
});

it('renders the configured loading state', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')
            ->resolveUsing(fn (): string => 'resolved')
            ->loadingState('Fetching…'),
    ];

    Livewire::test(PostsTable::class)->assertSee('Fetching…');
});
