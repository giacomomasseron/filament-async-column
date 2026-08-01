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
