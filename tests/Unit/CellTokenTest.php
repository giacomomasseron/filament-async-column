<?php

use GiacomoMasseroni\AsyncColumn\Resolution\CellToken;

it('round-trips a simple token', function (): void {
    $token = new CellToken('status', '42');

    $decoded = CellToken::decode($token->encode());

    expect($decoded?->columnName)->toBe('status')
        ->and($decoded?->recordKey)->toBe('42');
});

it('round-trips record keys containing a colon', function (): void {
    $token = new CellToken('status', 'tenant:7:order:9');

    $decoded = CellToken::decode($token->encode());

    expect($decoded?->recordKey)->toBe('tenant:7:order:9');
});

it('rejects malformed tokens', function (string $malformed): void {
    expect(CellToken::decode($malformed))->toBeNull();
})->with([
    'empty' => '',
    'no separator' => 'c3RhdHVz',
    'too many segments' => 'YQ:Yg:Yw',
    'illegal characters' => 'not valid!:Yg',
    'empty column' => ':NDI',
    'undecodable length' => 'Y:Yg',
    'empty record key' => 'Yg:',
]);
