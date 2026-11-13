<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Infrastructure\Outbox\TruckPositionOnVehicleReport;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Reactor outbox: el lote de telemetría (`telemetry.vehicle_position_reported`) proyecta la posición en el
 * seguimiento de cada envío a bordo. Tolerante con payloads mal formados. Contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @param array<string, mixed> $payload
 */
function tpovrMessage(string $event, array $payload): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'test.aggregate',
        Uuid::generate()->value,
        $event,
        $payload,
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );
}

function tpovrBoundShipment(string $order): string
{
    $bus = app(CommandBus::class);
    $shipment = Uuid::generate()->value;
    $bus->dispatch(new OpenTracking(orderReference: $order));
    $bus->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: $shipment));

    return $shipment;
}

it('proyecta la posición del lote sobre el seguimiento de cada envío a bordo', function (): void {
    $shipment = tpovrBoundShipment('order-tpovr-1');

    app(TruckPositionOnVehicleReport::class)->dispatch(tpovrMessage('telemetry.vehicle_position_reported', [
        'lat' => -16.5,
        'lng' => -68.15,
        'reported_at' => '2026-11-10T12:00:00+00:00',
        'shipment_ids' => [$shipment],
    ]));

    $record = TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect((float) $record->last_lat)->toBe(-16.5)
        ->and((float) $record->last_lng)->toBe(-68.15);
});

it('ignora un payload sin coordenadas válidas', function (): void {
    $shipment = tpovrBoundShipment('order-tpovr-2');

    app(TruckPositionOnVehicleReport::class)->dispatch(tpovrMessage('telemetry.vehicle_position_reported', [
        'lat' => null,
        'lng' => -68.15,
        'reported_at' => '2026-11-10T12:00:00+00:00',
        'shipment_ids' => [$shipment],
    ]));

    expect(TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail()->last_lat)->toBeNull();
});

it('ignora un evento que no es telemetry.vehicle_position_reported', function (): void {
    $shipment = tpovrBoundShipment('order-tpovr-3');

    app(TruckPositionOnVehicleReport::class)->dispatch(tpovrMessage('telemetry.other', [
        'lat' => -16.5,
        'lng' => -68.15,
        'reported_at' => '2026-11-10T12:00:00+00:00',
        'shipment_ids' => [$shipment],
    ]));

    expect(TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail()->last_lat)->toBeNull();
});
