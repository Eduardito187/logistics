<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;

it('builds a proof of delivery with confirmation and optional photo', function (): void {
    $pod = ProofOfDelivery::of('QR-OK-123', 'https://cdn/foto.jpg', new DateTimeImmutable('2026-10-13T12:00:00+00:00'));

    expect($pod->confirmation)->toBe('QR-OK-123')
        ->and($pod->photoUrl)->toBe('https://cdn/foto.jpg')
        ->and($pod->capturedAt->format(DATE_ATOM))->toBe('2026-10-13T12:00:00+00:00');
});

it('accepts a null photo and normalizes a blank photo to null', function (): void {
    $withNull = ProofOfDelivery::of('firma', null, new DateTimeImmutable('2026-10-13T12:00:00+00:00'));
    $withBlank = ProofOfDelivery::of('firma', '   ', new DateTimeImmutable('2026-10-13T12:00:00+00:00'));

    expect($withNull->photoUrl)->toBeNull()
        ->and($withBlank->photoUrl)->toBeNull();
});

it('requires a non-empty confirmation', function (): void {
    expect(fn () => ProofOfDelivery::of('  ', 'https://cdn/foto.jpg', new DateTimeImmutable))
        ->toThrow(ExecutionException::class, 'confirmación');
});

it('compares by value', function (): void {
    $when = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $a = ProofOfDelivery::of('QR', null, $when);
    $b = ProofOfDelivery::of('QR', null, $when);

    expect($a->equals($b))->toBeTrue();
});

it('keeps the durable storage reference (photo path) and normalizes a blank one to null', function (): void {
    $when = new DateTimeImmutable('2026-10-13T12:00:00+00:00');

    $withPath = ProofOfDelivery::of('firma', null, $when, 'pod/SHP-1/abc.jpg');
    $withBlank = ProofOfDelivery::of('firma', null, $when, '   ');
    $default = ProofOfDelivery::of('firma', null, $when);

    expect($withPath->photoPath)->toBe('pod/SHP-1/abc.jpg')
        ->and($withBlank->photoPath)->toBeNull()
        ->and($default->photoPath)->toBeNull();
});

it('distinguishes two proofs that differ only by photo path', function (): void {
    $when = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $a = ProofOfDelivery::of('QR', null, $when, 'pod/a.jpg');
    $b = ProofOfDelivery::of('QR', null, $when, 'pod/b.jpg');

    expect($a->equals($b))->toBeFalse();
});
