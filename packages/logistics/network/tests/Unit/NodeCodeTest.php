<?php

declare(strict_types=1);

use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\NodeCode;

it('accepts a well-formed code', function (): void {
    expect(NodeCode::fromString('CD-LP-01')->toString())->toBe('CD-LP-01');
});

it('normalizes to upper case and trims whitespace', function (): void {
    expect(NodeCode::fromString('  cd-lp-01  ')->toString())->toBe('CD-LP-01');
});

it('compares two codes by value', function (): void {
    expect(NodeCode::fromString('CD-LP-01')->equals(NodeCode::fromString('cd-lp-01')))->toBeTrue()
        ->and(NodeCode::fromString('CD-LP-01')->equals(NodeCode::fromString('CD-LP-02')))->toBeFalse();
});

it('rejects malformed codes', function (string $value, string $reason): void {
    expect(fn () => NodeCode::fromString($value))->toThrow(NetworkException::class, $reason);
})->with([
    'no dash' => ['CDLP01', 'formato'],
    'pipe separator' => ['CD|LP', 'formato'],
    'space inside' => ['CD LP 01', 'formato'],
    'too short' => ['A-', 'longitud'],
    'too long' => ['CD-LAPAZ-ELALTO-00001', 'longitud'],
]);
