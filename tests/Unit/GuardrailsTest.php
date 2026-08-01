<?php

use GiacomoMasseroni\AsyncColumn\Columns\AsyncColumn;

it('rejects getStateUsing with a message pointing at resolveUsing', function (): void {
    AsyncColumn::make('slow')->getStateUsing(fn (): string => 'x');
})->throws(LogicException::class, 'resolveUsing()');

it('rejects bare sortable', function (): void {
    AsyncColumn::make('slow')->sortable();
})->throws(LogicException::class, 'cannot be sorted');

it('rejects bare searchable', function (): void {
    AsyncColumn::make('slow')->searchable();
})->throws(LogicException::class, 'cannot be searched');

it('allows sortable with a developer-supplied query', function (): void {
    $column = AsyncColumn::make('slow')->sortable(query: fn ($query) => $query);

    expect($column->isSortable())->toBeTrue();
});

it('returns real text for the default error state, not a raw translation key', function (): void {
    $state = AsyncColumn::make('slow')->getErrorState();

    expect((string) $state)->toBe('Could not load')
        ->and((string) $state)->not->toContain('::');
});

it('hands the exception to errorStateUsing', function (): void {
    $column = AsyncColumn::make('slow')
        ->errorStateUsing(fn (Throwable $exception): string => 'saw: '.$exception->getMessage());

    expect((string) $column->getErrorState(new RuntimeException('boom')))->toBe('saw: boom');
});
