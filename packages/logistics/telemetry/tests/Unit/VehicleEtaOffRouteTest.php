<?php

declare(strict_types=1);

use DateTimeImmutable;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Contract\ActiveRouteReader;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Application\Query\GetVehicleEtas\GetVehicleEtas;
use Logistics\Telemetry\Application\Query\GetVehicleEtas\GetVehicleEtasHandler;
use Logistics\Telemetry\Application\Service\StopEtaCalculator;
use Logistics\Telemetry\Domain\Contract\StopEtaRepository;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Logistics\Telemetry\Domain\Model\VehiclePing;

// El estado "fuera de ruta" que la app muestra como cartel sale del backend: desvío sostenido más allá del
// mínimo (histéresis que evita el parpadeo), y nunca se afirma con la posición añeja. Se arma con dobles
// livianos de los puertos; no toca la base, pero el handler lee `config('logistics-telemetry.deviation...')`,
// así que se arranca la app (TestCase) sin RefreshDatabase para que el helper `config()` esté disponible.
uses(Tests\TestCase::class);

const VEO_NOW = '2026-11-16T12:00:00+00:00';

const VEO_VEHICLE = '11111111-1111-7111-8111-111111111111';

/** Reloj fijo para el test. */
function veoClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable(VEO_NOW);
        }
    };
}

/** Repositorio de posición con una última lectura a `$positionAgeS` de ahora y un `off_route_since` dado. */
function veoPositions(int $positionAgeS, ?DateTimeImmutable $offRouteSince): VehiclePositionRepository
{
    return new class($positionAgeS, $offRouteSince) implements VehiclePositionRepository
    {
        public function __construct(private int $ageS, private ?DateTimeImmutable $since) {}

        public function upsertFromPing(VehiclePing $ping, bool $moving): void {}

        public function all(): array
        {
            return [];
        }

        public function forVehicle(string $vehicleId): ?array
        {
            return [
                'vehicle_id' => $vehicleId,
                'lat' => -16.5,
                'lng' => -68.15,
                'speed_kph' => null,
                'heading' => null,
                'ignition' => null,
                'moving' => false,
                'route_id' => null,
                'recorded_at' => (new DateTimeImmutable(VEO_NOW))->modify("-{$this->ageS} seconds"),
            ];
        }

        public function changedSince(DateTimeImmutable $since): array
        {
            return [];
        }

        public function visible(?DateTimeImmutable $since, ?array $onlyVehicleIds = null, array $exceptVehicleIds = []): array
        {
            return [];
        }

        public function watchState(string $vehicleId): array
        {
            return ['speeding_since' => null, 'off_route_since' => $this->since];
        }

        public function saveWatchState(string $vehicleId, ?DateTimeImmutable $speedingSince, ?DateTimeImmutable $offRouteSince): void {}
    };
}

/** Rutas vacías (no hacen falta paradas para decidir el estado de desvío). */
function veoRoutes(): ActiveRouteReader
{
    return new class implements ActiveRouteReader
    {
        public function plannedGeometry(string $vehicleId, DateTimeImmutable $day): array
        {
            return [];
        }

        public function remainingStops(string $vehicleId, DateTimeImmutable $day): array
        {
            return [];
        }

        public function vehiclesWithOpenRoutes(DateTimeImmutable $day): array
        {
            return [];
        }
    };
}

function veoFleet(): FleetDirectory
{
    return new class implements FleetDirectory
    {
        public function plates(array $vehicleIds): array
        {
            return [];
        }

        public function driverNames(array $driverIds): array
        {
            return [];
        }

        public function homeBases(array $vehicleIds): array
        {
            return [];
        }

        public function assignedDriverIds(array $vehicleIds, DateTimeImmutable $day): array
        {
            return [];
        }

        public function vehicleIdsInDepartment(string $department): array
        {
            return [];
        }

        public function retiredVehicleIds(?array $among = null): array
        {
            return [];
        }
    };
}

function veoStored(): StopEtaRepository
{
    return new class implements StopEtaRepository
    {
        public function replaceForVehicle(string $vehicleId, array $rows): void {}

        public function forVehicle(string $vehicleId): array
        {
            return [];
        }
    };
}

/** @return array{off_route: bool, off_route_since: string|null} */
function veoResolve(int $positionAgeS, ?DateTimeImmutable $offRouteSince): array
{
    $clock = veoClock();
    $positions = veoPositions($positionAgeS, $offRouteSince);
    $calculator = new StopEtaCalculator($positions, veoRoutes(), $clock);
    $handler = new GetVehicleEtasHandler($calculator, veoStored(), veoFleet(), $clock, $positions);

    $view = $handler->handle(new GetVehicleEtas(VEO_VEHICLE))->toArray();

    return ['off_route' => $view['off_route'], 'off_route_since' => $view['off_route_since']];
}

it('afirma fuera de ruta cuando el desvío lleva sostenido al menos el mínimo (posición fresca)', function (): void {
    config(['logistics-telemetry.deviation.min_seconds' => 120]);
    $since = (new DateTimeImmutable(VEO_NOW))->modify('-200 seconds');

    expect(veoResolve(30, $since)['off_route'])->toBeTrue();
});

it('no afirma fuera de ruta mientras el desvío no superó el mínimo (histéresis)', function (): void {
    config(['logistics-telemetry.deviation.min_seconds' => 120]);
    $since = (new DateTimeImmutable(VEO_NOW))->modify('-60 seconds');

    expect(veoResolve(30, $since)['off_route'])->toBeFalse();
});

it('sin desvío registrado no está fuera de ruta', function (): void {
    expect(veoResolve(30, null)['off_route'])->toBeFalse();
});

it('con la posición añeja no afirma fuera de ruta aunque el desvío sea viejo', function (): void {
    config([
        'logistics-telemetry.deviation.min_seconds' => 120,
        'logistics-telemetry.eta.max_position_age_minutes' => 30,
    ]);
    $since = (new DateTimeImmutable(VEO_NOW))->modify('-3600 seconds');

    // Posición de hace 45 min: el camión dejó de reportar, no hay verdad en vivo.
    expect(veoResolve(2700, $since)['off_route'])->toBeFalse();
});
