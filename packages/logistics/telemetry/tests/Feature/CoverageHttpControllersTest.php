<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\AlertFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\GeofenceFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\PingFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\TripFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\VehiclePositionFactory;

/**
 * Cobertura de los controllers HTTP del contexto Telemetría a través de sus rutas reales de la API v1
 * (puertas app e integrations). Patrón F del brief: se omite el middleware de puerta (auth/device) para
 * ejercitar solo el contrato de los controllers, sus FormRequest y la cadena CQRS detrás (comandos,
 * consultas, ReadModels, repositorios y mappers). No caza bugs: recorre el happy-path y algún 422.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('ingiere un lote de pings por la puerta app', function (): void {
    $this->postJson('/api/v1/app/telemetry/pings', [
        'vehicle_id' => '0192aaaa-bbbb-7ccc-8ddd-000000000010',
        'driver_id' => 'driver-1',
        'route_id' => 'route-1',
        'pings' => [
            ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 30.0, 'heading' => 90, 'accuracy_m' => 5.0, 'ignition' => true, 'odometer_m' => 1000, 'device_id' => 'dev-1'],
            ['lat' => -16.501, 'lng' => -68.151, 'recorded_at' => '2026-10-13T10:01:00-04:00', 'speed_kph' => 35.0],
        ],
    ])->assertStatus(202)
        ->assertJsonPath('data.source', 'app')
        ->assertJsonPath('data.count', 2);
});

it('ingiere un lote de pings por la puerta integrations', function (): void {
    $this->postJson('/api/v1/integrations/telemetry/pings', [
        'vehicle_id' => '0192aaaa-bbbb-7ccc-8ddd-000000000011',
        'pings' => [
            ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00'],
        ],
    ])->assertStatus(202)
        ->assertJsonPath('data.source', 'integration');
});

it('rechaza un lote de pings sin lecturas con 422', function (): void {
    $this->postJson('/api/v1/app/telemetry/pings', [
        'vehicle_id' => '0192aaaa-bbbb-7ccc-8ddd-000000000012',
        'pings' => [],
    ])->assertStatus(422);
});

it('devuelve la posición en vivo de la flota', function (): void {
    VehiclePositionFactory::new()->create();

    $this->getJson('/api/v1/app/telemetry/fleet/live')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('devuelve la traza de un vehículo entre dos instantes', function (): void {
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-000000000013';
    PingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-05 08:30:00'))->create();

    $this->getJson("/api/v1/app/telemetry/vehicles/{$vehicleId}/track?from=2026-10-05T00:00:00-04:00&to=2026-10-06T00:00:00-04:00")
        ->assertOk()
        ->assertJsonStructure(['data']);

    // Sin rango: usa la ventana por defecto de 24 horas.
    $this->getJson("/api/v1/app/telemetry/vehicles/{$vehicleId}/track")->assertOk();
});

it('devuelve 422 cuando el rango de la traza es inválido', function (): void {
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-000000000014';

    $this->getJson("/api/v1/app/telemetry/vehicles/{$vehicleId}/track?from=no-es-fecha")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_range');
});

it('devuelve el resumen de telemetría de un vehículo en un día', function (): void {
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-000000000015';
    TripFactory::new()->state([
        'vehicle_id' => $vehicleId,
        'started_at' => new DateTimeImmutable('2026-10-05 08:00:00'),
        'ended_at' => new DateTimeImmutable('2026-10-05 09:00:00'),
    ])->create();

    $this->getJson("/api/v1/app/telemetry/vehicles/{$vehicleId}/summary?date=2026-10-05")
        ->assertOk()
        ->assertJsonPath('data.date', '2026-10-05');

    // Sin fecha: asume hoy.
    $this->getJson("/api/v1/app/telemetry/vehicles/{$vehicleId}/summary")->assertOk();
});

it('lista trips con y sin filtro de vehículo', function (): void {
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-000000000016';
    TripFactory::new()->state(['vehicle_id' => $vehicleId])->create();

    $this->getJson("/api/v1/app/telemetry/trips?vehicle_id={$vehicleId}&date=".date('Y-m-d'))->assertOk();
    $this->getJson('/api/v1/app/telemetry/trips')->assertOk();
});

it('lista alertas con filtros y las reconoce', function (): void {
    $alert = AlertFactory::new()->ofType(AlertType::Speeding, AlertSeverity::Critical)->create();

    $this->getJson('/api/v1/app/telemetry/alerts?acknowledged=false')
        ->assertOk()
        ->assertJsonStructure(['data']);
    $this->getJson('/api/v1/app/telemetry/alerts?vehicle_id='.$alert->getAttribute('vehicle_id').'&type='.AlertType::Speeding->value)
        ->assertOk();

    $this->postJson('/api/v1/app/telemetry/alerts/'.$alert->getKey().'/acknowledge')
        ->assertStatus(202)
        ->assertJsonPath('data.alert_id', (string) $alert->getKey());
});

it('lista geocercas con y sin filtro de tipo', function (): void {
    GeofenceFactory::new()->ofType(GeofenceType::ForbiddenZone)->create();

    $this->getJson('/api/v1/app/telemetry/geofences')->assertOk();
    $this->getJson('/api/v1/app/telemetry/geofences?type='.GeofenceType::ForbiddenZone->value)->assertOk();
});

it('define, renombra y redibuja una geocerca por sus endpoints', function (): void {
    $this->postJson('/api/v1/app/telemetry/geofences', [
        'name' => 'Zona nueva',
        'type' => GeofenceType::ForbiddenZone->value,
        'polygon' => [
            ['lat' => -16.55, 'lng' => -68.20],
            ['lat' => -16.55, 'lng' => -68.10],
            ['lat' => -16.45, 'lng' => -68.10],
        ],
        'node_ref' => 'node-1',
    ])->assertStatus(202)
        ->assertJsonPath('data.name', 'Zona nueva');

    $geofence = GeofenceFactory::new()->create();

    $this->postJson('/api/v1/app/telemetry/geofences/'.$geofence->getKey().'/rename', [
        'name' => 'Renombrada',
    ])->assertStatus(202);

    $this->postJson('/api/v1/app/telemetry/geofences/'.$geofence->getKey().'/redraw', [
        'polygon' => [
            ['lat' => -16.56, 'lng' => -68.21],
            ['lat' => -16.56, 'lng' => -68.11],
            ['lat' => -16.46, 'lng' => -68.11],
        ],
    ])->assertStatus(202)
        ->assertJsonPath('data.geofence_id', (string) $geofence->getKey());
});

it('rechaza una geocerca con polígono insuficiente con 422', function (): void {
    $this->postJson('/api/v1/app/telemetry/geofences', [
        'name' => 'Incompleta',
        'type' => GeofenceType::ForbiddenZone->value,
        'polygon' => [
            ['lat' => -16.55, 'lng' => -68.20],
        ],
    ])->assertStatus(422);
});

it('activa, desactiva y borra una geocerca por sus endpoints', function (): void {
    $geofence = GeofenceFactory::new()->inactive()->create();

    $this->postJson('/api/v1/app/telemetry/geofences/'.$geofence->getKey().'/activate')->assertStatus(202);
    $this->postJson('/api/v1/app/telemetry/geofences/'.$geofence->getKey().'/deactivate')->assertStatus(202);
    $this->deleteJson('/api/v1/app/telemetry/geofences/'.$geofence->getKey())->assertStatus(202);
});

it('devuelve el tráfico agregado de calles con y sin bbox', function (): void {
    $this->getJson('/api/v1/app/telemetry/streets')->assertOk();
    $this->getJson('/api/v1/app/telemetry/streets?bbox=-17.0,-68.3,-16.0,-68.0')->assertOk();
    // bbox mal formado: el controller lo ignora y devuelve todo.
    $this->getJson('/api/v1/app/telemetry/streets?bbox=invalido')->assertOk();
});
