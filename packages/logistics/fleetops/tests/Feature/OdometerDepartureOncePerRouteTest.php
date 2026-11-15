<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometer;
use Logistics\FleetOps\Application\Query\ListOdometerReadings\ListOdometerReadings;
use Logistics\FleetOps\Domain\Exception\DepartureAlreadyRecorded;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

// N salidas por día: cada SALIDA (ruta) registra UN solo odómetro de salida, simétrico de la llegada. Una
// segunda salida de la misma ruta es un conflicto (409 departure_already_recorded); otra ruta del mismo
// camión el mismo día, o una lectura sin ruta, no se ven afectadas.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function odoDeparture(string $vehicleId, int $km, string $readAt, ?string $routeId): void
{
    app(CommandBus::class)->dispatch(new RecordOdometer($vehicleId, $km, 'departure', $readAt, null, $routeId));
}

it('rechaza una segunda salida de la misma ruta', function (): void {
    $vehicleId = (string) Str::uuid7();
    $routeId = (string) Str::uuid7();

    odoDeparture($vehicleId, 1000, '2026-11-14T08:00:00-04:00', $routeId);

    expect(fn () => odoDeparture($vehicleId, 1001, '2026-11-14T08:05:00-04:00', $routeId))
        ->toThrow(DepartureAlreadyRecorded::class);

    expect(app(QueryBus::class)->ask(new ListOdometerReadings($vehicleId, null, null, null, null, $routeId)))
        ->toHaveCount(1);
});

it('el mismo camión registra una salida por cada ruta del día, y sin ruta no hay tope', function (): void {
    $vehicleId = (string) Str::uuid7();
    $routeA = (string) Str::uuid7();
    $routeB = (string) Str::uuid7();

    odoDeparture($vehicleId, 1000, '2026-11-14T08:00:00-04:00', $routeA);
    odoDeparture($vehicleId, 1080, '2026-11-14T14:00:00-04:00', $routeB);
    odoDeparture($vehicleId, 1090, '2026-11-14T18:00:00-04:00', null);
    odoDeparture($vehicleId, 1095, '2026-11-14T19:00:00-04:00', null);

    expect(app(QueryBus::class)->ask(new ListOdometerReadings($vehicleId)))->toHaveCount(4);
});

it('responde 409 departure_already_recorded por HTTP con el motivo en detail y error.message', function (): void {
    $body = [
        'vehicle_id' => (string) Str::uuid7(),
        'km' => 2000,
        'source' => 'departure',
        'read_at' => '2026-11-14T08:00:00-04:00',
        'route_id' => (string) Str::uuid7(),
    ];

    $this->postJson('/api/v1/app/fleetops/odometer', $body)->assertStatus(202);

    $response = $this->postJson('/api/v1/app/fleetops/odometer', ['km' => 2001] + $body)
        ->assertStatus(409)
        ->assertJsonPath('code', 'departure_already_recorded')
        ->assertJsonPath('error.code', 'departure_already_recorded');

    expect($response->json('detail'))->toBe($response->json('error.message'))
        ->and($response->json('detail'))->toContain('odómetro de salida');
});
