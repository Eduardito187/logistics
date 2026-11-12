<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Un vehículo NO APTO (checklist pre-viaje con ítem crítico fallido y no liberado: fleet_vehicles.unfit_since
 * vigente en America/La_Paz) se RECHAZA con 422 al asignarle un pedido, y el comando de atar a la ruta
 * también lo rechaza. Si la columna todavía no existe (Flota sin migrar), el chequeo se omite.
 */
beforeEach(function (): void {
    if (!Schema::hasColumn('fleet_vehicles', 'unfit_since')) {
        test()->markTestSkipped('fleet_vehicles.unfit_since todavía no existe (migración de Flota).');
    }

    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
    $user = User::factory()->create();
    $user->assignRole(Role::Supervisor);
    $this->actingAs($user, 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const UNFIT_DETAIL_TEXT = 'Vehicle not fit to dispatch (failed critical pre-trip item, not cleared)';

function unfitVehicle(?string $unfitSince): string
{
    $id = Uuid::generate()->value;
    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => strtoupper(substr(str_replace('-', '', $id), -6)),
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => '[]',
        'unfit_since' => $unfitSince,
    ]);

    return $id;
}

function unfitOrder(string $orderNumber): string
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
        'fulfillment_mode' => 'OWNED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $shipmentId;
}

it('rechaza con 422 asignar un pedido a un vehículo no apto', function (): void {
    $order = 'UNFIT-01';
    unfitOrder($order);
    $vehicle = unfitVehicle(now('America/La_Paz')->subDay()->toDateString());

    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $vehicle])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation')
        ->assertJsonPath('detail', UNFIT_DETAIL_TEXT);

    expect(DB::table('execution_driver_routes')->where('vehicle_id', $vehicle)->exists())->toBeFalse();
});

it('permite el vehículo apto o con no-aptitud futura', function (): void {
    $order = 'UNFIT-02';
    unfitOrder($order);

    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => unfitVehicle(null)])->assertOk();

    $future = unfitVehicle(now('America/La_Paz')->addDays(2)->toDateString());
    $this->postJson("/api/v1/app/orders/{$order}/vehicle", ['vehicle_id' => $future])->assertOk();
});

it('el comando de atar a la ruta también rechaza el vehículo no apto', function (): void {
    $vehicle = unfitVehicle(now('America/La_Paz')->toDateString());

    app(CommandBus::class)->dispatch(new AttachShipmentToRoute(
        vehicleId: $vehicle,
        shipmentId: Uuid::generate()->value,
        orderReference: 'UNFIT-03',
        customerName: null,
        address: null,
    ));
})->throws(ExecutionException::class, UNFIT_DETAIL_TEXT);
