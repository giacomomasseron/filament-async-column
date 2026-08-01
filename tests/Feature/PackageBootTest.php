<?php

use Filament\Support\Facades\FilamentAsset;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;

it('boots the package and its config', function (): void {
    expect(config('async-column.cache_prefix'))->toBe('async-column');
});

it('has a working database fixture', function (): void {
    Post::create(['title' => 'First']);

    expect(Post::count())->toBe(1);
});

it('registers the JS and CSS assets under the package name', function (): void {
    $scripts = FilamentAsset::getScripts(['giacomomasseron/filament-async-column']);
    $styles = FilamentAsset::getStyles(['giacomomasseron/filament-async-column']);

    expect($scripts)->toHaveCount(1)
        ->and($scripts[array_key_first($scripts)]->getId())->toBe('async-column')
        ->and($styles)->toHaveCount(1)
        ->and($styles[array_key_first($styles)]->getId())->toBe('async-column');
});
