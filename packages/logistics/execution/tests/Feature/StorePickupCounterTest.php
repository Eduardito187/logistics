<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

// Retiro en tienda: el cliente retira en el mostrador y el personal le entrega con la misma prueba de
// entrega del chofer, pero SIN camión ni ruta. Y la lista de carga del camión puede pedir solo las entregas
// abiertas (sin las ya entregadas o fallidas).
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function spcPod(string $shipmentId, bool $counter): RecordProofOfDelivery
{
    return new RecordProofOfDelivery(
        shipmentId: $shipmentId,
        unitId: 'U-1',
        confirmation: 'FIRMA_DIGITAL',
        photoUrl: null,
        photoPath: 'pod/'.$shipmentId.'/foto.jpg',
        lat: -17.78,
        lng: -63.18,
        deviceId: 'device-tienda',
        userId: 'user-tienda',
        occurredAt: '2026-11-14T10:00:00-04:00',
        idempotencyKey: 'pod-'.$shipmentId,
        counterHandover: $counter,
    );
}

it('la tienda entrega en el mostrador un pedido sin vehículo ni ruta y queda entregado', function (): void {
    $shipmentId = (string) Str::uuid7();

    app(CommandBus::class)->dispatch(spcPod($shipmentId, true));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect($delivery->status)->toBe('DELIVERED')
        ->and($delivery->vehicle_id)->toBeNull()
        ->and($delivery->pod_confirmation)->toBe('FIRMA_DIGITAL')
        ->and(DB::table('outbox_messages')->where('event_name', 'execution.shipment_delivered')->where('payload->shipment_id', $shipmentId)->exists())->toBeTrue();
});

it('sin ser entrega de mostrador, un envío sin entrega ni ruta sigue respondiendo el error claro', function (): void {
    $shipmentId = (string) Str::uuid7();

    expect(fn () => app(CommandBus::class)->dispatch(spcPod($shipmentId, false)))
        ->toThrow(ExecutionException::class, 'no hay entrega abierta');

    expect(DeliveryRecord::query()->where('shipment_id', $shipmentId)->exists())->toBeFalse();
});

it('la lista de entregas del vehículo puede excluir las entregadas y las fallidas', function (): void {
    $vehicleId = (string) Str::uuid7();
    foreach (['LOADED', 'IN_TRANSIT', 'DELIVERED', 'FAILED'] as $status) {
        DeliveryRecord::factory()->create(['vehicle_id' => $vehicleId, 'status' => $status]);
    }

    $all = app(QueryBus::class)->ask(new ListDeliveriesByVehicle($vehicleId));
    $open = app(QueryBus::class)->ask(new ListDeliveriesByVehicle($vehicleId, null, 0, ['LOADED', 'IN_TRANSIT', 'PENDING', 'PREPARED']));

    $statuses = array_map(static fn ($view): string => $view->status, $open->items);
    sort($statuses);

    expect($all->items)->toHaveCount(4)
        ->and($statuses)->toBe(['IN_TRANSIT', 'LOADED']);
});
