<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometer;
use Logistics\FleetOps\Application\Query\ListOdometerReadings\ListOdometerReadings;
use Logistics\FleetOps\Domain\Exception\RouteAlreadyClosed;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

// Odómetro ligado a la ruta del chofer: la lectura de salida/llegada lleva su `route_id`, y una ruta se
// cierra UNA sola vez (una segunda llegada de la misma ruta es un conflicto, no una lectura más).
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function orcRecord(string $vehicleId, int $km, string $source, string $readAt, ?string $routeId): void
{
    app(CommandBus::class)->dispatch(new RecordOdometer($vehicleId, $km, $source, $readAt, null, $routeId));
}

it('guarda la ruta de la lectura y permite listar por ruta', function (): void {
    $vehicleId = (string) Str::uuid7();
    $routeId = (string) Str::uuid7();

    orcRecord($vehicleId, 1000, 'departure', '2026-11-14T08:00:00-04:00', $routeId);
    orcRecord($vehicleId, 1080, 'arrival', '2026-11-14T17:00:00-04:00', $routeId);
    orcRecord($vehicleId, 1090, 'manual', '2026-11-14T18:00:00-04:00', null);

    $ofRoute = app(QueryBus::class)->ask(new ListOdometerReadings($vehicleId, null, null, null, null, $routeId));

    expect($ofRoute)->toHaveCount(2)
        ->and(array_unique(array_map(static fn ($view): ?string => $view->routeId, $ofRoute)))->toBe([$routeId]);
});

it('rechaza una segunda llegada de la misma ruta', function (): void {
    $vehicleId = (string) Str::uuid7();
    $routeId = (string) Str::uuid7();

    orcRecord($vehicleId, 1000, 'departure', '2026-11-14T08:00:00-04:00', $routeId);
    orcRecord($vehicleId, 1080, 'arrival', '2026-11-14T17:00:00-04:00', $routeId);

    expect(fn () => orcRecord($vehicleId, 1085, 'arrival', '2026-11-14T17:30:00-04:00', $routeId))
        ->toThrow(RouteAlreadyClosed::class);
});

it('otra ruta, o una llegada sin ruta, no se ven afectadas', function (): void {
    $vehicleId = (string) Str::uuid7();
    $routeA = (string) Str::uuid7();
    $routeB = (string) Str::uuid7();

    orcRecord($vehicleId, 1080, 'arrival', '2026-11-14T12:00:00-04:00', $routeA);
    orcRecord($vehicleId, 1150, 'arrival', '2026-11-14T18:00:00-04:00', $routeB);
    orcRecord($vehicleId, 1160, 'arrival', '2026-11-14T19:00:00-04:00', null);
    orcRecord($vehicleId, 1170, 'arrival', '2026-11-14T20:00:00-04:00', null);

    expect(app(QueryBus::class)->ask(new ListOdometerReadings($vehicleId)))->toHaveCount(4);
});
