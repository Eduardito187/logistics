<?php

declare(strict_types=1);

use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Auditoría #24 (schemas estables con nombre) y #20 (paginación estándar + rango de fechas) de fleetops.
 *
 * Fija la FORMA exacta de `maintenance/due` (DueMaintenanceItem) y `utilization` (VehicleUtilization):
 * si alguien renombra una clave, este test falla antes de que la pantalla de la app quede en blanco.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Registra un vehículo en flota y devuelve [id, placa].
 *
 * @return array{0: string, 1: string}
 */
function registrarVehiculoFlota(): array
{
    $plate = strtoupper('SC'.random_int(1000, 9999));

    app(CommandBus::class)->dispatch(new RegisterVehicle(
        plate: $plate,
        capacityKg: 3500,
        capacityM3: 18.0,
        homeBaseNodeId: Uuid::generate()->value,
        documents: [],
    ));

    return [(string) VehicleModel::query()->where('plate', $plate)->firstOrFail()->getKey(), $plate];
}

it('maintenance/due devuelve la forma estable DueMaintenanceItem, con placa, km restantes y urgencia', function (): void {
    [$vehicleId, $plate] = registrarVehiculoFlota();

    // Plan por km servido a 100 000 (próximo a 110 000) y odómetro actual 115 000 → vencido.
    $plan = MaintenancePlan::define(MaintenancePlanId::generate(), $vehicleId, ServiceKind::OilChange, 10_000, null);
    $plan->recordServiceDone(Odometer::km(100_000), new DateTimeImmutable('2026-10-01T00:00:00+00:00'));
    app(MaintenancePlanRepository::class)->save($plan);

    $this->postJson('/api/v1/app/fleetops/odometer', [
        'vehicle_id' => $vehicleId,
        'km' => 115_000,
        'source' => 'manual',
        'read_at' => '2026-10-05T08:00:00+00:00',
    ])->assertStatus(202);

    $response = $this->getJson("/api/v1/app/fleetops/maintenance/due?vehicle_id={$vehicleId}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(array_keys($response->json('data.0')))->toBe([
        'vehicle_id', 'vehicle_plate', 'plan_id', 'service_kind', 'next_due_km', 'next_due_at',
        'current_odometer_km', 'remaining_km', 'days_remaining', 'overdue', 'urgency',
    ]);

    $response->assertJsonPath('data.0.vehicle_plate', $plate)
        ->assertJsonPath('data.0.plan_id', $plan->id()->toString())
        ->assertJsonPath('data.0.remaining_km', -5_000)
        ->assertJsonPath('data.0.overdue', true)
        ->assertJsonPath('data.0.urgency', 'overdue');

    // Filtro por urgencia y paginación opt-in con el meta estándar.
    $this->getJson("/api/v1/app/fleetops/maintenance/due?vehicle_id={$vehicleId}&urgency=due_soon")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/v1/app/fleetops/maintenance/due?vehicle_id={$vehicleId}&per_page=10")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.total_pages', 1)
        ->assertJsonPath('meta.next_page', null);

    $this->getJson('/api/v1/app/fleetops/maintenance/due?urgency=whenever')->assertStatus(422);
});

it('utilization devuelve la forma estable VehicleUtilization con la placa', function (): void {
    [$vehicleId, $plate] = registrarVehiculoFlota();

    $response = $this->getJson("/api/v1/app/fleetops/utilization?vehicle_id={$vehicleId}&from=2026-10-01&to=2026-10-31")
        ->assertOk()
        ->assertJsonPath('data.vehicle_plate', $plate);

    expect(array_keys($response->json('data')))->toBe([
        'vehicle_id', 'vehicle_plate', 'from', 'to', 'departures_count', 'km_traveled', 'hours_on_road',
        'last_odometer_km', 'last_odometer_read_at',
    ]);
});

it('pagina órdenes de mantenimiento (más recientes primero) y filtra por rango de fecha agendada', function (): void {
    $vehicleId = Uuid::generate()->value;

    foreach (['2026-10-01', '2026-10-10', '2026-10-20'] as $day) {
        $this->postJson('/api/v1/app/fleetops/maintenance/orders', [
            'vehicle_id' => $vehicleId,
            'type' => 'preventive',
            'odometer_km' => 90_000,
            'scheduled_at' => "{$day}T09:00:00+00:00",
        ])->assertStatus(202);
    }

    $page = $this->getJson("/api/v1/app/fleetops/maintenance/orders?vehicle_id={$vehicleId}&per_page=2&page=1")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 2)
        ->assertJsonPath('meta.next_page', 2);

    expect((string) $page->json('data.0.scheduled_at'))->toStartWith('2026-10-20');

    $this->getJson("/api/v1/app/fleetops/maintenance/orders?vehicle_id={$vehicleId}&from=2026-10-05&to=2026-10-15")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Sin parámetros de paginación: contrato histórico (colección completa, sin meta).
    $this->getJson("/api/v1/app/fleetops/maintenance/orders?vehicle_id={$vehicleId}")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonMissingPath('meta');
});

it('pagina planes de mantenimiento y salidas con el meta estándar', function (): void {
    $vehicleId = Uuid::generate()->value;

    foreach (['oil_change', 'tires', 'brakes'] as $kind) {
        $this->postJson('/api/v1/app/fleetops/maintenance/plans', [
            'vehicle_id' => $vehicleId,
            'service_kind' => $kind,
            'interval_km' => 10_000,
        ])->assertStatus(202);
    }

    $this->getJson("/api/v1/app/fleetops/maintenance/plans?vehicle_id={$vehicleId}&per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.next_page', 2);

    foreach (['2026-10-01T06:00:00+00:00', '2026-10-02T06:00:00+00:00'] as $at) {
        $this->postJson('/api/v1/app/fleetops/departures', [
            'vehicle_id' => $vehicleId,
            'departed_at' => $at,
        ])->assertStatus(202);
    }

    $this->getJson("/api/v1/app/fleetops/departures?vehicle_id={$vehicleId}&per_page=1&page=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.next_page', 2)
        ->assertJsonPath('data.0.departed_at', fn (string $value): bool => str_starts_with($value, '2026-10-02'));
});
