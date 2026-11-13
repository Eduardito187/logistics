<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;

/**
 * Documento con vencimiento: comparación por día, ventana de alerta y estado legible para el badge.
 */
it('rechaza un tipo de documento vacío', function (): void {
    expect(fn () => DocumentExpiry::of('   ', new DateTimeImmutable('2027-01-01')))
        ->toThrow(FleetException::class, 'no puede estar vacío');
});

it('recorta el tipo y lo expone en forma primitiva', function (): void {
    $doc = DocumentExpiry::of('  soat  ', new DateTimeImmutable('2027-01-01'));

    expect($doc->type)->toBe('soat')
        ->and($doc->toArray())->toBe(['type' => 'soat', 'expires_on' => '2027-01-01']);
});

it('considera vencido solo a partir del día siguiente al vencimiento', function (): void {
    $doc = DocumentExpiry::of('soat', new DateTimeImmutable('2026-10-13'));

    expect($doc->isExpiredAt(new DateTimeImmutable('2026-10-13 23:00:00')))->toBeFalse()
        ->and($doc->isExpiredAt(new DateTimeImmutable('2026-10-14')))->toBeTrue();
});

it('rechaza una ventana de días negativa', function (): void {
    $doc = DocumentExpiry::of('soat', new DateTimeImmutable('2027-01-01'));

    expect(fn () => $doc->expiresWithin(-1, new DateTimeImmutable('2026-10-13')))
        ->toThrow(FleetException::class, 'no puede ser negativa');
});

it('incluye los documentos ya vencidos en la ventana de por vencer', function (): void {
    $doc = DocumentExpiry::of('soat', new DateTimeImmutable('2026-10-01'));

    expect($doc->expiresWithin(5, new DateTimeImmutable('2026-10-13')))->toBeTrue();
});

it('clasifica el estado del documento a la fecha de referencia', function (string $expiresOn, int $soonDays, string $expected): void {
    $doc = DocumentExpiry::of('soat', new DateTimeImmutable($expiresOn));

    expect($doc->status(new DateTimeImmutable('2026-10-13'), $soonDays))->toBe($expected);
})->with([
    'vencido' => ['2026-10-01', 30, 'expired'],
    'por vencer pronto' => ['2026-10-20', 30, 'expiring_soon'],
    'vigente y lejos' => ['2027-06-01', 30, 'valid'],
]);
