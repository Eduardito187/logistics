<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Hoja de ruta del vehículo (#18) y parada de la ruta (#19 + destino): bultos (units_total / unit_ids /
 * units_loaded), cliente y dirección corta, paginación opt-in, y destination.node_id en la parada.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
    $this->actingAs(User::factory()->create(), 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un envío con N bultos (un producto con cantidad 5, para probar que NO se suma la cantidad).
 *
 * @return list<string> ids de los bultos
 */
function vdupShipment(string $shipmentId, int $units, ?string $nodeId = null): array
{
    DB::table('shipments')->insert([
        'id' => $shipmentId,
        'source' => 'TEST',
        'external_id' => 'EXT-'.$shipmentId,
        'order_reference' => 'ORD-'.strtoupper(substr(str_replace('-', '', $shipmentId), -8)),
        'customer_name' => 'Cliente Bultos',
        'address_text' => 'Av. Banzer 123, Equipetrol, Santa Cruz',
        'address_confidence' => 90,
        'address_confidence_level' => 'high',
        'destination_node_id' => $nodeId,
        'status' => 'PLANNED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $line = Uuid::generate()->value;
    DB::table('shipment_lines')->insert([
        'id' => $line,
        'shipment_id' => $shipmentId,
        'sku' => 'SKU-TV',
        'product_name' => 'Televisor 55',
        'quantity' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ids = [];
    for ($i = 1; $i <= $units; $i++) {
        $id = Uuid::generate()->value;
        DB::table('shipment_units')->insert([
            'id' => $id,
            'shipment_id' => $shipmentId,
            'line_id' => $line,
            'unit_index' => $i,
            'unit_total' => $units,
            'piece_index' => 1,
            'piece_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ids[] = $id;
    }

    return $ids;
}

it('expone bultos, cliente y dirección corta en la hoja de ruta del vehículo', function (): void {
    $vehicle = Uuid::generate()->value;
    $shipment = Uuid::generate()->value;
    $units = vdupShipment($shipment, 2);

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new RecordPreparationScan($shipment, $vehicle, $units[0], -16.5, -68.15, 'dev', 'usr', '2026-11-12T08:00:00-04:00', 'vdup-prep-1'));
    $bus->dispatch(new RecordLoadScan($shipment, $units[0], -16.5, -68.15, 'dev', 'usr', '2026-11-12T08:10:00-04:00', 'vdup-load-1'));
    // Reescanear el mismo bulto no suma.
    $bus->dispatch(new RecordLoadScan($shipment, $units[0], -16.5, -68.15, 'dev', 'usr', '2026-11-12T08:11:00-04:00', 'vdup-load-2'));

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/deliveries")
        ->assertOk()
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.customer_name', 'Cliente Bultos')
        ->assertJsonPath('data.0.short_address', 'Av. Banzer 123')
        ->assertJsonPath('data.0.units_total', 2)
        ->assertJsonPath('data.0.unit_ids', $units)
        ->assertJsonPath('data.0.units_loaded', 1);
});

it('pagina la hoja de ruta solo si se pide (meta estándar)', function (): void {
    $vehicle = Uuid::generate()->value;

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    foreach (range(1, 3) as $n) {
        $shipment = Uuid::generate()->value;
        $bus->dispatch(new RecordPreparationScan($shipment, $vehicle, 'U-'.$n, -16.5, -68.15, 'dev', 'usr', '2026-11-12T08:00:00-04:00', 'vdup-page-'.$n));
    }

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/deliveries?page=1&per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 2)
        ->assertJsonPath('meta.next_page', 2);

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/deliveries")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.count', 3);
});

it('la parada de la ruta trae units_total en bultos y el destino con node_id', function (): void {
    $vehicle = Uuid::generate()->value;
    $home = Uuid::generate()->value;
    $store = Uuid::generate()->value;
    $node = Uuid::generate()->value;
    vdupShipment($home, 3);
    vdupShipment($store, 1, $node);

    DB::table('network_nodes')->insert([
        'id' => $node,
        'code' => 'T-SCZ-99',
        'name' => 'Tienda Prueba',
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicle,
        routeDate: '2026-11-12',
        stops: [
            new RouteStopInput(shipmentId: $home, sequence: 1),
            new RouteStopInput(shipmentId: $store, sequence: 2),
        ],
    ));

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=2026-11-12")
        ->assertOk()
        // 3 bultos aunque la línea diga cantidad 5: el POD espera bultos.
        ->assertJsonPath('data.stops.0.units_total', 3)
        ->assertJsonPath('data.stops.0.destination.type', 'home')
        ->assertJsonPath('data.stops.0.destination.node_id', null)
        ->assertJsonPath('data.stops.1.destination.type', 'store')
        ->assertJsonPath('data.stops.1.destination.node_id', $node)
        ->assertJsonPath('data.stops.1.destination.name', 'Tienda Prueba');
});
