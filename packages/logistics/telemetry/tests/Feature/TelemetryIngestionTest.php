<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\GeofenceFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\AlertRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\TripRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Ingesta end-to-end de telemetría por el CommandBus contra el stack completo (PostgreSQL real, base
 * logistics_test con RefreshDatabase). Verifica que una tanda de pings deja la proyección de lectura
 * (pings + posición viva + trip), que el mal uso levanta alertas y que cada mutación deja su evento en
 * el outbox transaccional. Se marca skip cuando no hay PostgreSQL, para no romper la suite Unit.
 */
beforeEach(function (): void {
    $this->vehicleId = '0192aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee';
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('ingests a batch of pings leaving pings, a live position and a trip', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    // Recorrido corto en La Paz, dentro de horario laboral, a velocidad moderada.
    $pings = [
        ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 30.0, 'heading' => 90, 'ignition' => true],
        ['lat' => -16.501, 'lng' => -68.151, 'recorded_at' => '2026-10-13T10:01:00-04:00', 'speed_kph' => 35.0, 'heading' => 90, 'ignition' => true],
        ['lat' => -16.502, 'lng' => -68.152, 'recorded_at' => '2026-10-13T10:02:00-04:00', 'speed_kph' => 40.0, 'heading' => 90, 'ignition' => true],
    ];

    $commands->dispatch(new RecordVehiclePings($this->vehicleId, 'driver-1', 'route-1', 'app', $pings));

    // Los tres pings quedan persistidos y asociados a un trip.
    expect(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(3)
        ->and(PingRecord::query()->where('vehicle_id', $this->vehicleId)->whereNotNull('trip_id')->count())->toBe(3);

    // Hay una posición viva para el vehículo.
    $position = VehiclePositionRecord::query()->find($this->vehicleId);
    expect($position)->not->toBeNull();

    // Y un trip del vehículo.
    expect(TripRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1);
});

it('raises speeding and geofence_violation alerts for a reading at 100 km/h inside a forbidden zone', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    // Geocerca prohibida activa que cubre el punto de la lectura (cuadrado alrededor de La Paz).
    GeofenceFactory::new()
        ->ofType(GeofenceType::ForbiddenZone)
        ->state([
            'name' => 'Forbidden downtown',
            'polygon' => [
                ['lat' => -16.55, 'lng' => -68.20],
                ['lat' => -16.55, 'lng' => -68.10],
                ['lat' => -16.45, 'lng' => -68.10],
                ['lat' => -16.45, 'lng' => -68.20],
            ],
        ])
        ->create();

    // Lectura a 100 km/h (> límite 90) dentro de la zona prohibida, en horario laboral.
    $pings = [
        ['lat' => -16.50, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 100.0, 'ignition' => true],
    ];

    $commands->dispatch(new RecordVehiclePings($this->vehicleId, 'driver-1', 'route-1', 'app', $pings));

    $types = AlertRecord::query()->where('vehicle_id', $this->vehicleId)->pluck('type')->all();

    expect($types)->toContain(AlertType::Speeding->value)
        ->and($types)->toContain(AlertType::GeofenceViolation->value);
});

it('appends an event to the outbox for every telemetry mutation', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    $pings = [
        ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 30.0, 'ignition' => true],
        ['lat' => -16.501, 'lng' => -68.151, 'recorded_at' => '2026-10-13T10:01:00-04:00', 'speed_kph' => 35.0, 'ignition' => true],
    ];

    $commands->dispatch(new RecordVehiclePings($this->vehicleId, 'driver-1', 'route-1', 'app', $pings));

    // El trip arrancado deja su evento en el outbox bajo aggregate_type 'telemetry.%'.
    $outbox = DB::table('outbox_messages')->where('aggregate_type', 'like', 'telemetry.%')->get();

    expect($outbox)->not->toBeEmpty()
        ->and($outbox->pluck('aggregate_type')->all())->toContain('telemetry.trip');
});
