<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * La ruta del chofer ("mi ruta") se sirve ENRIQUECIDA cross-context para la vista "Entrega en curso" de
 * la app: cada parada trae el teléfono del cliente y la referencia de dirección (desde shipments) y las
 * líneas del pedido con miniatura (desde shipment_lines), y la ruta trae la geometría por calles (desde
 * el plan en planning). El enriquecimiento lo resuelve el QueryHandler por lectura directa, sin acoplar
 * el dominio de ejecución. Se omite el middleware de puerta para ejercitar solo el contrato del endpoint.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra la cabecera del envío (teléfono + referencia) y una línea con miniatura, por lectura directa.
 */
function seedShipmentForStop(string $shipmentId, string $phone, string $reference): void
{
    DB::table('shipments')->insert([
        'id' => $shipmentId,
        'source' => 'TEST',
        'external_id' => 'EXT-'.substr($shipmentId, 0, 8),
        'customer_name' => 'Cliente Enriquecido',
        'customer_phone' => $phone,
        'address_text' => 'Calle Falsa 123',
        'address_reference' => $reference,
        'lat' => -16.5,
        'lng' => -68.15,
        'address_confidence' => 90,
        'address_confidence_level' => 'high',
        'status' => 'RECEIVED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('shipment_lines')->insert([
        'id' => Uuid::generate()->value,
        'shipment_id' => $shipmentId,
        'sku' => 'SKU-1',
        'product_name' => 'Refrigerador',
        'product_image_url' => 'https://cdn.example/fridge.jpg',
        'quantity' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('sirve la ruta del chofer con telefono, referencia, items y geometria', function (): void {
    $vehicle = Uuid::generate()->value;
    $shipment = Uuid::generate()->value;
    $date = '2026-10-16';

    seedShipmentForStop($shipment, '+591 70000001', 'Portón negro, segundo piso');

    // Plan del mismo vehículo y día con geometría de ruta por calles (así se enlaza con la ruta).
    DB::table('planning_plans')->insert([
        'id' => Uuid::generate()->value,
        'plan_date' => $date,
        'vehicle_id' => $vehicle,
        'status' => 'Published',
        'route_geometry' => json_encode([
            'type' => 'LineString',
            'coordinates' => [[-68.15, -16.5], [-68.16, -16.51]],
            'distance_meters' => 1234.5,
            'duration_seconds' => 300.0,
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicle,
        routeDate: $date,
        stops: [
            new RouteStopInput(
                shipmentId: $shipment,
                sequence: 1,
                orderReference: 'ORD-1',
                customerName: 'Cliente Enriquecido',
                address: 'Calle Falsa 123',
                etaAt: '2026-10-16T09:00:00+00:00',
                lat: -16.5,
                lng: -68.15,
            ),
        ],
    ));

    $response = $this->getJson("/api/v1/app/vehicles/{$vehicle}/route?date={$date}")
        ->assertOk();

    // Enriquecimiento de la parada (shipments).
    $response->assertJsonPath('data.stops.0.customer_phone', '+591 70000001');
    $response->assertJsonPath('data.stops.0.address_reference', 'Portón negro, segundo piso');
    $response->assertJsonPath('data.stops.0.items.0.sku', 'SKU-1');
    $response->assertJsonPath('data.stops.0.items.0.name', 'Refrigerador');
    $response->assertJsonPath('data.stops.0.items.0.quantity', 2);
    $response->assertJsonPath('data.stops.0.items.0.image_url', 'https://cdn.example/fridge.jpg');

    // La parada actual (siguiente a atender) también viaja enriquecida.
    $response->assertJsonPath('data.current_stop.customer_phone', '+591 70000001');

    // Geometría de la ruta (planning), con su forma primitiva esquema RouteGeometry.
    $response->assertJsonPath('data.route_geometry.type', 'LineString');
    $response->assertJsonPath('data.route_geometry.coordinates.0.0', -68.15);
    $response->assertJsonPath('data.route_geometry.distance_meters', 1234.5);
});

it('sirve la ruta con valores nulos cuando no hay datos cross-context', function (): void {
    $vehicle = Uuid::generate()->value;
    $shipment = Uuid::generate()->value;
    $date = '2026-10-16';

    // No se siembra ni envío ni plan: el enriquecimiento no inventa datos.
    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicle,
        routeDate: $date,
        stops: [new RouteStopInput(shipmentId: $shipment, sequence: 1)],
    ));

    $this->getJson("/api/v1/app/vehicles/{$vehicle}/route?date={$date}")
        ->assertOk()
        ->assertJsonPath('data.stops.0.customer_phone', null)
        ->assertJsonPath('data.stops.0.address_reference', null)
        ->assertJsonPath('data.stops.0.items', [])
        ->assertJsonPath('data.route_geometry', null);
});
