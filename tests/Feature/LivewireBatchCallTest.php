<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;
use GiacomoMasseroni\AsyncColumn\Livewire\AsyncColumnHook;
use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\Post;
use GiacomoMasseroni\AsyncColumn\Tests\Fixtures\PostsTable;
use Livewire\Component;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

it('resolves a batch through a call the component does not define', function (): void {
    $post = Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'resolved-value'),
    ];

    $token = (new CellToken('slow', (string) $post->getKey()))->encode();

    $returned = Livewire::test(PostsTable::class)
        ->call(AsyncColumnHook::METHOD, [$token])
        ->effects['returns'][0] ?? null;

    expect($returned['cells'][$token]['html'])->toContain('resolved-value');
});

it('tolerates an empty batch', function (): void {
    Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    Livewire::test(PostsTable::class)
        ->call(AsyncColumnHook::METHOD, [])
        ->assertOk();
});

it('skips the re-render entirely, so the response carries no re-rendered HTML', function (): void {
    $post = Post::create(['title' => 'One']);

    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'resolved-value'),
    ];

    $token = (new CellToken('slow', (string) $post->getKey()))->encode();

    $effects = Livewire::test(PostsTable::class)
        ->call(AsyncColumnHook::METHOD, [$token])
        ->effects;

    // skipRender() means HandleComponents::render() never runs, so the 'html'
    // effect key is never populated — proving the table query was not re-run
    // and no DOM morph will fight the cell the client already injected.
    expect($effects)->not->toHaveKey('html');
});

it('does not intercept an unrelated, genuinely undefined method on a HasTable component', function (): void {
    PostsTable::$columns = [
        AsyncColumn::make('slow')->resolveUsing(fn (): string => 'v'),
    ];

    // A method name the hook must not recognise: it must fall straight through
    // to Livewire's own "method not found" handling, proving the hook checks
    // the method name and doesn't just swallow every call on a HasTable.
    Livewire::test(PostsTable::class)->call('someUndefinedMethod');
})->throws(MethodNotFoundException::class);

it('does not intercept the batch method on a component that has no table', function (): void {
    $plain = new class extends Component
    {
        public function greet(): string
        {
            return 'hello';
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    // Sanity: this component works normally and is untouched by the hook.
    $returned = Livewire::test($plain)
        ->call('greet')
        ->effects['returns'][0] ?? null;

    expect($returned)->toBe('hello');

    // The batch method name means nothing on a component that isn't a
    // HasTable: the hook must decline, and Livewire must report it as any
    // other undefined method.
    Livewire::test($plain)->call(AsyncColumnHook::METHOD, ['whatever']);
})->throws(MethodNotFoundException::class);
