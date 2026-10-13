<?php

declare(strict_types=1);

use Logistics\Shared\Domain\ValueObject\Uuid;

it('genera un UUID con formato RFC válido y versión 7', function (): void {
    $uuid = Uuid::generate();

    expect($uuid->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('embebe el timestamp en los bits altos y lo recupera', function (): void {
    $millis = 1_760_000_000_000;

    $uuid = Uuid::generateAt($millis);

    expect($uuid->timestampMillis())->toBe($millis);
});

it('es ordenable en el tiempo: un UUID posterior es mayor como texto', function (): void {
    $before = Uuid::generateAt(1_000);
    $after = Uuid::generateAt(2_000);

    expect(strcmp($after->value, $before->value))->toBeGreaterThan(0);
});

it('reconstruye desde texto y compara por igualdad', function (): void {
    $original = Uuid::generate();

    $restored = Uuid::fromString((string) $original);

    expect($restored->equals($original))->toBeTrue();
});

it('rechaza un texto que no es UUID', function (): void {
    Uuid::fromString('no-es-un-uuid');
})->throws(InvalidArgumentException::class);

it('genera identificadores distintos en dos llamadas seguidas', function (): void {
    expect(Uuid::generate()->value)->not->toBe(Uuid::generate()->value);
});
