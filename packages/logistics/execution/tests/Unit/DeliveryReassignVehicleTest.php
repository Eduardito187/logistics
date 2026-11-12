<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\DeliveryVehicleReassigned;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;

// Regla del dueño: reasignar el pedido mueve la entrega al ÚLTIMO camión mientras está EN CURSO; una
// entrega REAL ya cerrada (entregada o fallida) nunca se mueve (no se pierde el POD/desenlace).

const DRV_REASSIGN_AT = '2026-11-12T15:00:00+00:00';

function pendingDeliveryOn(string $vehicleId, DeliveryStatus $status = DeliveryStatus::Pending): Delivery
{
    return Delivery::reconstitute(
        DeliveryId::generate(),
        'ship-1',
        $vehicleId,
        $status,
        [],
        null,
        null,
    );
}

it('mueve una entrega en curso al camión nuevo y graba el evento de reasignación', function (): void {
    $delivery = pendingDeliveryOn('veh-viejo');

    $moved = $delivery->reassignVehicle('veh-nuevo', new DateTimeImmutable(DRV_REASSIGN_AT));

    expect($moved)->toBeTrue()
        ->and($delivery->vehicleId())->toBe('veh-nuevo');

    $events = $delivery->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DeliveryVehicleReassigned::class);
    /** @var DeliveryVehicleReassigned $event */
    $event = $events[0];
    expect($event->fromVehicleId)->toBe('veh-viejo')
        ->and($event->toVehicleId)->toBe('veh-nuevo');
});

it('NO mueve una entrega ya entregada (se respeta el POD)', function (): void {
    $delivery = pendingDeliveryOn('veh-viejo', DeliveryStatus::Delivered);

    $moved = $delivery->reassignVehicle('veh-nuevo', new DateTimeImmutable(DRV_REASSIGN_AT));

    expect($moved)->toBeFalse()
        ->and($delivery->vehicleId())->toBe('veh-viejo')
        ->and($delivery->releaseEvents())->toBe([]);
});

it('NO mueve una entrega fallida', function (): void {
    $delivery = pendingDeliveryOn('veh-viejo', DeliveryStatus::Failed);

    expect($delivery->reassignVehicle('veh-nuevo', new DateTimeImmutable(DRV_REASSIGN_AT)))->toBeFalse()
        ->and($delivery->vehicleId())->toBe('veh-viejo');
});

it('es idempotente: reasignar al mismo camión no hace nada', function (): void {
    $delivery = pendingDeliveryOn('veh-1');

    expect($delivery->reassignVehicle('veh-1', new DateTimeImmutable(DRV_REASSIGN_AT)))->toBeFalse()
        ->and($delivery->releaseEvents())->toBe([]);
});
