<?php

declare(strict_types=1);

use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Infrastructure\Availability\StubSlotReserver;

/*
| Stub determinista del puerto de reserva de cupo. Concede cupo ilimitado: reservar siempre devuelve
| true y liberar nunca falla, incluso liberando una franja que no estaba reservada.
*/

it('reservar siempre concede cupo', function (): void {
    $reserver = new StubSlotReserver;
    $slot = DeliverySlot::of(new DateTimeImmutable('2026-10-18'), 'AM');

    expect($reserver->reserve('zone-1', $slot))->toBeTrue()
        ->and($reserver->reserve('zone-1', $slot))->toBeTrue();
});

it('liberar nunca falla, aun sobre una franja no reservada', function (): void {
    $reserver = new StubSlotReserver;
    $slot = DeliverySlot::of(new DateTimeImmutable('2026-10-18'), 'PM');

    $reserver->reserve('zone-1', $slot);
    $reserver->release('zone-1', $slot);
    $reserver->release('zone-1', $slot);

    // Reservar de nuevo tras liberar de más sigue concediendo cupo.
    expect($reserver->reserve('zone-1', $slot))->toBeTrue();
});
