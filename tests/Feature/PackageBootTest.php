<?php

use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;

it('boots the package and its config', function (): void {
    expect(config('async-column.cache_prefix'))->toBe('async-column');
});

it('has a working database fixture', function (): void {
    Post::create(['title' => 'First']);

    expect(Post::count())->toBe(1);
});
