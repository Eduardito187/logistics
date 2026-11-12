<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * POST /app/orders/{orderNumber}/vehicle — asigna el pedido a un camión real y lo deja en «Mi ruta de hoy»
 * del chofer de ese vehículo. 422 si el vehículo no existe/no está operativo; 404 si el pedido no existe.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);

    $user = User::factory()->create();
    $user->assignRole(Role::Supervisor);
    $this->actingAs($user, 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function aovVehicle(string $status = 'available'): string
{
    $id = Uuid::generate()->value;
    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => strtoupper(substr($id, 0, 6)),
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => $status,
        'documents' => '[]',
    ]);

    return $id;
}

function aovOrder(string $orderNumber): string
{
    $shipmentId = Uuid::generate()->value;
    DB::table('shipments')->insert([
        'id' => $shipmentId,
        'source' => 'test',
        'external_id' => $orderNumber,
        'order_reference' => $orderNumber,
        'customer_name' => 'Cliente Demo',
        'address_text' => 'Av. Siempre Viva 123',
        'address_confidence' => 90,
        'address_confidence_level' => 'high',
        'status' => 'PLANNED',
        'fulfillment_mode' => 'owned',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $shipmentId;
}

it('asigna el pedido al camión y lo deja en la ruta de hoy del chofer', function (): void {
    $order = 'DEMO-AOV-01';
    $shipmentId = aovOrder($order);
    $vehicleId = aovVehicle();

    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $vehicleId])
        ->assertOk()
        ->assertJsonPath('data.order_reference', $order)
        ->assertJsonPath('data.vehicle_id', $vehicleId)
        ->assertJsonPath('data.shipments.0.shipment_id', $shipmentId);

    /** @var DriverRouteView|null $route */
    $route = app(QueryBus::class)->ask(new GetDriverRoute($vehicleId, null));
    expect($route)->not->toBeNull()
        ->and(collect($route->stops)->pluck('shipmentId')->all())->toContain($shipmentId);
});

it('rechaza con 422 un vehículo que no existe o no está operativo', function (): void {
    $order = 'DEMO-AOV-02';
    aovOrder($order);

    // Vehículo inexistente.
    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => Uuid::generate()->value])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');

    // Vehículo en mantenimiento (no operativo).
    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => aovVehicle('maintenance')])
        ->assertStatus(422);
});

it('devuelve 404 si el pedido no existe', function (): void {
    $this->postJson('/api/v1/app/orders/NO-EXISTE-999/vehicle', ['vehicle_id' => aovVehicle()])
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});

it('abre la entrega del envío al asignar el vehículo (habilita carga y POD del chofer)', function (): void {
    $order = 'DEMO-AOV-04';
    $shipmentId = aovOrder($order);
    $vehicleId = aovVehicle();

    // En el flujo nuevo del dueño, asignar el vehículo ES preparar: debe abrir la entrega, o el POD
    // fallaría en dominio ("no hay entrega abierta para el envío").
    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $vehicleId])->assertOk();

    $delivery = app(DeliveryRepository::class)->findByShipmentId($shipmentId);
    expect($delivery)->not->toBeNull();

    // Reasignar no abre una segunda entrega (idempotente).
    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $vehicleId])->assertOk();
    expect(DB::table('execution_deliveries')->where('shipment_id', $shipmentId)->count())->toBe(1);
});

it('reasignar el pedido MUEVE la entrega en curso al camión nuevo', function (): void {
    $order = 'DEMO-AOV-05';
    $shipmentId = aovOrder($order);
    $v1 = aovVehicle();
    $v2 = aovVehicle();

    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $v1])->assertOk();
    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $v2])->assertOk();

    // El pedido queda en el ÚLTIMO camión asignado (ese chofer lo entrega).
    $delivery = app(DeliveryRepository::class)->findByShipmentId($shipmentId);
    expect($delivery)->not->toBeNull()
        ->and($delivery->vehicleId())->toBe($v2);
    // Y la entrega no se duplicó por reasignar.
    expect(DB::table('execution_deliveries')->where('shipment_id', $shipmentId)->count())->toBe(1);

    // La RUTA queda consistente: el pedido aparece SOLO en el camión nuevo, no fantasma en el viejo.
    $oldRoute = app(QueryBus::class)->ask(new GetDriverRoute($v1, null));
    $newRoute = app(QueryBus::class)->ask(new GetDriverRoute($v2, null));
    expect(collect($oldRoute->stops)->pluck('shipmentId')->all())->not->toContain($shipmentId)
        ->and(collect($newRoute->stops)->pluck('shipmentId')->all())->toContain($shipmentId);
});

it('es idempotente: reasignar el mismo pedido no duplica la parada', function (): void {
    $order = 'DEMO-AOV-03';
    $shipmentId = aovOrder($order);
    $vehicleId = aovVehicle();

    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $vehicleId])->assertOk();
    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $vehicleId])->assertOk();

    /** @var DriverRouteView $route */
    $route = app(QueryBus::class)->ask(new GetDriverRoute($vehicleId, null));
    $matching = collect($route->stops)->filter(fn ($s): bool => $s->shipmentId === $shipmentId);
    expect($matching)->toHaveCount(1);
});
