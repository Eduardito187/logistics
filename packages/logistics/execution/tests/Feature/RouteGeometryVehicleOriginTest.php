<?php

declare(strict_types=1);

use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Contract\RouteGeometryReader;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// El mapa del chofer arranca en SU punto de salida (la sede/almacén base del vehículo; para una
// transportadora, su carrier_hub), no en el origen del envío. Sin plan, el respaldo (recta entre paradas)
// antepone esa sede; si el vehículo no tiene sede resoluble, la ruta arranca en la primera parada (como antes).

const ORIGIN_ROUTE_DATE = '2026-10-16';

beforeEach(function (): void {
    $this->withoutMiddleware();
    config()->set('logistics-planning.auto_planner.origin_node_id', null);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Arma la ruta del chofer con dos paradas geocodificadas para el vehículo dado.
 */
function assembleOriginRoute(string $vehicleId): void
{
    $a = (string) ShipmentRecordFactory::new()->create(['lat' => -16.50, 'lng' => -68.13])->getKey();
    $b = (string) ShipmentRecordFactory::new()->create(['lat' => -16.52, 'lng' => -68.15])->getKey();

    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicleId,
        routeDate: ORIGIN_ROUTE_DATE,
        stops: [
            new RouteStopInput(shipmentId: $a, sequence: 1, lat: -16.50, lng: -68.13),
            new RouteStopInput(shipmentId: $b, sequence: 2, lat: -16.52, lng: -68.15),
        ],
    ));
}

it('con sede del vehículo resoluble, la geometría de respaldo ARRANCA en la sede (almacén de salida)', function (): void {
    // Nodo sede del camión (carrier_hub) con coordenadas conocidas: [lng, lat] = [-68.10, -16.40].
    $node = NodeFactory::new()->carrierHub()->create(['lat' => -16.40, 'lng' => -68.10]);
    $vehicle = (string) VehicleFactory::new()->homeBase((string) $node->getKey())->create()->getKey();

    assembleOriginRoute($vehicle);

    $geometry = app(RouteGeometryReader::class)->forVehicleAndDate($vehicle, ORIGIN_ROUTE_DATE);

    // Origen (sede) + dos paradas = tres puntos; el PRIMERO es la sede del vehículo, no la primera parada.
    expect($geometry)->not->toBeNull()
        ->and($geometry['coordinates'])->toHaveCount(3)
        ->and($geometry['coordinates'][0])->toBe([-68.10, -16.40])
        ->and($geometry['coordinates'][1])->toBe([-68.13, -16.50])
        ->and($geometry['coordinates'][2])->toBe([-68.15, -16.52]);
});

it('sin sede resoluble (vehículo sin fila en flota), la ruta arranca en la primera parada (comportamiento previo)', function (): void {
    $vehicle = Uuid::generate()->value;

    assembleOriginRoute($vehicle);

    $geometry = app(RouteGeometryReader::class)->forVehicleAndDate($vehicle, ORIGIN_ROUTE_DATE);

    // Sin origen anterpuesto: solo las dos paradas, empezando en la primera.
    expect($geometry)->not->toBeNull()
        ->and($geometry['coordinates'])->toHaveCount(2)
        ->and($geometry['coordinates'][0])->toBe([-68.13, -16.50]);
});
