<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Execution\Application\Command\OpenDelivery\OpenDelivery;
use Logistics\Labels\Application\Command\EmitOrderLabels\EmitOrderLabels;
use Logistics\Labels\Application\Contract\DispatchAuthorizationReader;
use Logistics\Labels\Application\Query\GetDispatchLines\GetDispatchLines;
use Logistics\Labels\Application\ReadModel\DispatchAuthorization;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Labels\Tests\Support\LabelTestEnv;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Autorización de despacho: un envío queda autorizado cuando YA TIENE CAMIÓN, sea por plan publicado o por
 * la asignación directa del andén (asignar el vehículo ES preparar: abre la entrega y suma la parada a la
 * ruta, sin plan). Antes solo contaba el plan publicado y el preparador quedaba bloqueado con un pedido que
 * ya tenía camión.
 */
function dauVehicle(?string $unfitSince = null): string
{
    $id = Uuid::generate()->value;
    $row = [
        'id' => $id,
        'plate' => strtoupper(substr(str_replace('-', '', $id), -6)),
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => '[]',
    ];
    if ($unfitSince !== null) {
        $row['unfit_since'] = $unfitSince;
    }
    DB::table('fleet_vehicles')->insert($row);

    return $id;
}

function dauShipment(string $orderNumber): string
{
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => $orderNumber,
        'order_reference' => $orderNumber,
        'destination_node_id' => null,
        'lat' => -17.80,
        'lng' => -63.20,
    ]);
    ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id, 'sku' => 'SKU-DAU', 'product_name' => 'Taladro', 'quantity' => 1]);

    return (string) $shipment->id;
}

/** Suma el envío a la ruta del camión, como hace la asignación del pedido desde el andén. */
function dauAttach(string $shipmentId, string $vehicleId, string $orderNumber): void
{
    app(CommandBus::class)->dispatch(new AttachShipmentToRoute(
        vehicleId: $vehicleId,
        shipmentId: $shipmentId,
        orderReference: $orderNumber,
        customerName: 'Cliente Demo',
        address: 'Av. Siempre Viva 123',
    ));
}

function dauAuthorization(string $shipmentId): DispatchAuthorization
{
    return app(DispatchAuthorizationReader::class)->forShipment($shipmentId);
}

it('no autoriza un envío sin camión por ninguna vía y da el código not_assigned', function (): void {
    $auth = dauAuthorization(dauShipment('ORD-DAU-1'));

    expect($auth->authorized)->toBeFalse()
        ->and($auth->reason)->toBe('sin plan publicado')
        ->and($auth->reasonCode)->toBe('not_assigned');
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('autoriza por plan publicado y no por un plan sin publicar', function (): void {
    $published = dauShipment('ORD-DAU-2');
    PlannedStopRecord::factory()->create(['plan_id' => PlanRecord::factory()->published()->create()->id, 'shipment_id' => $published]);

    $draft = dauShipment('ORD-DAU-3');
    PlannedStopRecord::factory()->create(['plan_id' => PlanRecord::factory()->create()->id, 'shipment_id' => $draft]);

    expect(dauAuthorization($published)->authorized)->toBeTrue()
        ->and(dauAuthorization($published)->reasonCode)->toBeNull()
        ->and(dauAuthorization($draft)->authorized)->toBeFalse();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('autoriza un envío con parada en la ruta de un camión aunque no tenga plan (caso SO-900001)', function (): void {
    $shipmentId = dauShipment('ORD-DAU-4');
    dauAttach($shipmentId, dauVehicle(), 'ORD-DAU-4');

    // Solo la parada en ruta: sin plan y sin entrega abierta.
    expect(DB::table('planning_plan_stops')->where('shipment_id', $shipmentId)->exists())->toBeFalse()
        ->and(DB::table('execution_deliveries')->where('shipment_id', $shipmentId)->exists())->toBeFalse();

    $line = app(QueryBus::class)->ask(new GetDispatchLines('ORD-DAU-4'))->toArray()['data'][0];

    expect($line['authorized'])->toBeTrue()
        ->and($line['authorized_reason'])->toBeNull()
        ->and($line['authorized_reason_code'])->toBeNull();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('autoriza un envío con entrega abierta por la asignación del vehículo, sin plan', function (): void {
    $shipmentId = dauShipment('ORD-DAU-5');
    app(CommandBus::class)->dispatch(new OpenDelivery($shipmentId, dauVehicle()));

    expect(dauAuthorization($shipmentId)->authorized)->toBeTrue();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('emite las etiquetas de un pedido asignado a un camión sin plan publicado', function (): void {
    LabelTestEnv::configureSigner();
    NodeRecord::factory()->distributionCenter()->inDepartment(Department::SantaCruz)->create(['code' => 'CD-DAU-SCZ', 'lat' => -17.7833, 'lng' => -63.1821]);

    $shipmentId = dauShipment('SCZ-DAU-6');
    $vehicleId = dauVehicle();
    dauAttach($shipmentId, $vehicleId, 'SCZ-DAU-6');
    app(CommandBus::class)->dispatch(new OpenDelivery($shipmentId, $vehicleId));

    app(CommandBus::class)->dispatch(new EmitOrderLabels('SCZ-DAU-6'));

    expect(EmittedLabelRecord::query()->where('order_id', 'SCZ-DAU-6')->count())->toBe(1);
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('no autoriza si el camión asignado quedó no apto, y vuelve a autorizar al liberarlo', function (): void {
    if (!Schema::hasColumn('fleet_vehicles', 'unfit_since')) {
        test()->markTestSkipped('fleet_vehicles.unfit_since todavía no existe (migración de Flota).');
    }

    $shipmentId = dauShipment('ORD-DAU-7');
    $vehicleId = dauVehicle();
    // Se asigna estando apto (la asignación rechaza un camión no apto) y DESPUÉS falla el pre-viaje.
    dauAttach($shipmentId, $vehicleId, 'ORD-DAU-7');
    DB::table('fleet_vehicles')->where('id', $vehicleId)->update(['unfit_since' => now('America/La_Paz')->toDateString()]);

    $line = app(QueryBus::class)->ask(new GetDispatchLines('ORD-DAU-7'))->toArray()['data'][0];
    expect($line['authorized'])->toBeFalse()
        ->and($line['authorized_reason'])->toBe('vehículo no apto')
        ->and($line['authorized_reason_code'])->toBe('vehicle_unfit');

    // No-aptitud futura: todavía puede salir hoy.
    DB::table('fleet_vehicles')->where('id', $vehicleId)->update(['unfit_since' => now('America/La_Paz')->addDays(2)->toDateString()]);
    expect(dauAuthorization($shipmentId)->authorized)->toBeTrue();

    DB::table('fleet_vehicles')->where('id', $vehicleId)->update(['unfit_since' => null]);
    expect(dauAuthorization($shipmentId)->authorized)->toBeTrue();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('el plan publicado sigue autorizando aunque el camión de una ruta esté no apto', function (): void {
    if (!Schema::hasColumn('fleet_vehicles', 'unfit_since')) {
        test()->markTestSkipped('fleet_vehicles.unfit_since todavía no existe (migración de Flota).');
    }

    $shipmentId = dauShipment('ORD-DAU-8');
    $vehicleId = dauVehicle();
    dauAttach($shipmentId, $vehicleId, 'ORD-DAU-8');
    DB::table('fleet_vehicles')->where('id', $vehicleId)->update(['unfit_since' => now('America/La_Paz')->toDateString()]);
    PlannedStopRecord::factory()->create(['plan_id' => PlanRecord::factory()->published()->create()->id, 'shipment_id' => $shipmentId]);

    expect(dauAuthorization($shipmentId)->authorized)->toBeTrue();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('una parada reprogramada no autoriza: el envío salió de esa ruta y espera replanificación', function (): void {
    $shipmentId = dauShipment('ORD-DAU-9');
    dauAttach($shipmentId, dauVehicle(), 'ORD-DAU-9');
    DB::table('execution_driver_route_stops')->where('shipment_id', $shipmentId)->update(['status' => 'RESCHEDULED']);

    $auth = dauAuthorization($shipmentId);

    expect($auth->authorized)->toBeFalse()
        ->and($auth->reasonCode)->toBe('not_assigned');
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');
