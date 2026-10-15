<?php

declare(strict_types=1);

use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los controllers HTTP de la puerta app de fleetops que la suite aún no recorría (Patrón F):
 * las LECTURAS de métricas y listados (ListRefuels, ListOdometerReadings, ListMaintenanceOrders,
 * ListMaintenancePlans, ListFuelPrices, GetVehicleUtilization, GetVehicleExpenseTotal,
 * GetVehicleCostSummary, GetMaintenanceCost) y la ESCRITURA de completar una orden de mantenimiento por su
 * ruta. Se omite el middleware de puerta y se ejercita el happy-path real contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('lista por los endpoints de lectura con filtros, tolerando tablas vacías', function (string $url): void {
    $this->getJson($url)
        ->assertOk()
        ->assertJsonStructure(['data']);
})->with([
    'refuels' => ['/api/v1/app/fleetops/refuels?vehicle_id='.'019299aa-0000-7000-8000-00000000c001'],
    'odometer' => ['/api/v1/app/fleetops/odometer?vehicle_id='.'019299aa-0000-7000-8000-00000000c002'],
    'maintenance-orders' => ['/api/v1/app/fleetops/maintenance/orders?vehicle_id='.'019299aa-0000-7000-8000-00000000c003'.'&status=scheduled'],
    'maintenance-plans' => ['/api/v1/app/fleetops/maintenance/plans?vehicle_id='.'019299aa-0000-7000-8000-00000000c004'],
    'fuel-prices' => ['/api/v1/app/fleetops/fuel-prices?fuel_type=diesel'],
]);

it('devuelve las métricas de un vehículo por sus endpoints de lectura', function (): void {
    $vehicleId = Uuid::generate()->value;
    $from = '2026-10-01';
    $to = '2026-10-31';

    $this->getJson("/api/v1/app/fleetops/utilization?vehicle_id={$vehicleId}&from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicleId);

    $this->getJson("/api/v1/app/fleetops/expenses/total?vehicle_id={$vehicleId}&from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicleId);

    $this->getJson("/api/v1/app/fleetops/cost-summary?vehicle_id={$vehicleId}&from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicleId);

    $this->getJson("/api/v1/app/fleetops/maintenance/cost?vehicle_id={$vehicleId}&from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('agenda y completa una orden de mantenimiento por sus endpoints', function (): void {
    $vehicleId = Uuid::generate()->value;

    $this->postJson('/api/v1/app/fleetops/maintenance/orders', [
        'vehicle_id' => $vehicleId,
        'type' => 'preventive',
        'odometer_km' => 90_000,
        'workshop' => 'Taller Norte',
        'scheduled_at' => '2026-10-05T09:00:00+00:00',
    ])->assertStatus(202);

    $orderId = (string) MaintenanceOrderRecord::query()->where('vehicle_id', $vehicleId)->value('id');
    expect($orderId)->not->toBe('');

    // Completar con sus ítems (comando CompleteMaintenance a través del controller y su FormRequest).
    $this->postJson("/api/v1/app/fleetops/maintenance/orders/{$orderId}/complete", [
        'items' => [
            ['description' => 'Cambio de aceite', 'qty' => 1, 'part_cost_cents' => 5_000, 'labor_cost_cents' => 2_000],
        ],
        'currency' => 'BOB',
    ])->assertStatus(202)
        ->assertJsonPath('data.order_id', $orderId);
});

it('rechaza con 422 completar una orden sin ítems', function (): void {
    $this->postJson('/api/v1/app/fleetops/maintenance/orders/'.Uuid::generate()->value.'/complete', [
        'currency' => 'BOB',
    ])->assertStatus(422);
});
