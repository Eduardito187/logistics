<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Una ruta armada desde el plan publicado deja la parada SIN entrega abierta (la entrega nacía solo al
// asignar el camión a mano). El chofer con el pedido en su ruta igual puede cargarlo o registrar un intento
// fallido: la entrega se abre con el camión de la ruta. Sin ruta se mantiene el error claro.
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Arma una ruta con una parada para el envío, sin abrirle entrega. Devuelve el camión de la ruta.
 */
function rswdRouteFor(string $shipmentId): string
{
    $vehicleId = Uuid::generate()->value;

    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicleId,
        routeDate: '2026-10-13',
        stops: [
            new RouteStopInput(
                shipmentId: $shipmentId,
                sequence: 1,
                orderReference: 'ORD-RSWD',
                customerName: 'Cliente Uno',
                address: 'Av. Siempre Viva 1',
                etaAt: '2026-10-13T09:00:00+00:00',
                lat: -16.5,
                lng: -68.15,
            ),
        ],
    ));

    return $vehicleId;
}

it('la carga abre la entrega con el camión de la ruta cuando faltaba', function (): void {
    $shipment = Uuid::generate()->value;
    $unit = Uuid::generate()->value;
    $vehicle = rswdRouteFor($shipment);

    expect(DB::table('execution_deliveries')->where('shipment_id', $shipment)->exists())->toBeFalse();

    app(CommandBus::class)->dispatch(new RecordLoadScan($shipment, $unit, -16.5, -68.15, 'dev', 'usr', '2026-10-13T08:00:00+00:00', 'load-'.$unit));

    $delivery = DB::table('execution_deliveries')->where('shipment_id', $shipment)->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->vehicle_id)->toBe($vehicle)
        ->and($delivery->status)->toBe('LOADED')
        ->and(DB::table('outbox_messages')->where('event_name', 'execution.delivery_opened')->where('payload->shipment_id', $shipment)->count())->toBe(1);
});

it('el intento fallido abre la entrega con el camión de la ruta y cierra la parada', function (): void {
    $shipment = Uuid::generate()->value;
    $unit = Uuid::generate()->value;
    $vehicle = rswdRouteFor($shipment);

    app(CommandBus::class)->dispatch(new RecordFailedDelivery(
        shipmentId: $shipment,
        unitId: $unit,
        reason: 'CUSTOMER_ABSENT',
        lat: -16.5,
        lng: -68.15,
        deviceId: 'dev',
        userId: 'usr',
        occurredAt: '2026-10-13T10:00:00+00:00',
        idempotencyKey: 'fail-'.$unit,
    ));

    $delivery = DB::table('execution_deliveries')->where('shipment_id', $shipment)->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->vehicle_id)->toBe($vehicle)
        ->and($delivery->status)->toBe('FAILED')
        ->and(DB::table('execution_driver_route_stops')->where('shipment_id', $shipment)->value('status'))->toBe('FAILED');
});

it('sin ruta ni entrega, la carga sigue respondiendo el error claro', function (): void {
    $shipment = Uuid::generate()->value;
    $unit = Uuid::generate()->value;

    expect(fn () => app(CommandBus::class)->dispatch(new RecordLoadScan($shipment, $unit, -16.5, -68.15, 'dev', 'usr', '2026-10-13T08:00:00+00:00', 'load-'.$unit)))
        ->toThrow(ExecutionException::class, 'no hay entrega abierta');

    expect(DB::table('execution_deliveries')->where('shipment_id', $shipment)->exists())->toBeFalse();
});
