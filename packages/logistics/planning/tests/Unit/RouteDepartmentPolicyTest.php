<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\AreaLocation;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Application\Policy\ClientStopLocation;
use Logistics\Planning\Application\Policy\RouteDepartmentPolicy;
use Logistics\Planning\Application\Policy\RouteSpansMultipleDepartments;

/**
 * Localizador de departamentos de prueba: resuelve cada punto por su latitud contra un mapa fijo. Una
 * latitud sin entrada devuelve null (ubicación desconocida / fuera de cobertura), igual que el contrato
 * real del DepartmentLocator.
 *
 * @param array<string, AreaLocation|null> $byLatitude
 */
function fakeLocator(array $byLatitude): DepartmentLocator
{
    return new readonly class($byLatitude) implements DepartmentLocator
    {
        /**
         * @param array<string, AreaLocation|null> $byLatitude
         */
        public function __construct(private array $byLatitude) {}

        public function locate(GeoPoint $point): ?AreaLocation
        {
            return $this->byLatitude[(string) $point->lat] ?? null;
        }
    };
}

it('resolves a single-city route as resolved and not intermunicipal', function (): void {
    $locator = fakeLocator([
        '-16.1' => new AreaLocation('dep-lp', 'La Paz', 'city-lp', 'La Paz'),
        '-16.2' => new AreaLocation('dep-lp', 'La Paz', 'city-lp', 'La Paz'),
    ]);
    $policy = new RouteDepartmentPolicy($locator);

    $scope = $policy->guard([
        ClientStopLocation::of('shp-1', -16.1, -68.1),
        ClientStopLocation::of('shp-2', -16.2, -68.2),
    ]);

    expect($scope->isResolved())->toBeTrue()
        ->and($scope->departmentId)->toBe('dep-lp')
        ->and($scope->departmentName)->toBe('La Paz')
        ->and($scope->intermunicipal)->toBeFalse();
});

it('marks an intermunicipal route when cities differ within the same department', function (): void {
    $locator = fakeLocator([
        '-16.1' => new AreaLocation('dep-lp', 'La Paz', 'city-lp', 'La Paz'),
        '-16.3' => new AreaLocation('dep-lp', 'La Paz', 'city-el-alto', 'El Alto'),
    ]);
    $policy = new RouteDepartmentPolicy($locator);

    $scope = $policy->guard([
        ClientStopLocation::of('shp-1', -16.1, -68.1),
        ClientStopLocation::of('shp-2', -16.3, -68.3),
    ]);

    expect($scope->isResolved())->toBeTrue()
        ->and($scope->departmentId)->toBe('dep-lp')
        ->and($scope->intermunicipal)->toBeTrue();
});

it('rejects a route that spans two departments', function (): void {
    $locator = fakeLocator([
        '-16.1' => new AreaLocation('dep-lp', 'La Paz', 'city-lp', 'La Paz'),
        '-17.8' => new AreaLocation('dep-sc', 'Santa Cruz', 'city-scz', 'Santa Cruz'),
    ]);
    $policy = new RouteDepartmentPolicy($locator);

    expect(fn () => $policy->guard([
        ClientStopLocation::of('shp-1', -16.1, -68.1),
        ClientStopLocation::of('shp-2', -17.8, -63.2),
    ]))->toThrow(RouteSpansMultipleDepartments::class, 'no puede cruzar departamentos');
});

it('exempts a CD-to-CD route (no client stops): scope is unknown, no throw', function (): void {
    $policy = new RouteDepartmentPolicy(fakeLocator([]));

    $scope = $policy->guard([]);

    expect($scope->isResolved())->toBeFalse()
        ->and($scope->departmentId)->toBeNull()
        ->and($scope->intermunicipal)->toBeFalse();
});

it('tolerates stops out of coverage: an unknown location neither blocks nor fixes the department', function (): void {
    $locator = fakeLocator([
        '-16.1' => new AreaLocation('dep-lp', 'La Paz', 'city-lp', 'La Paz'),
        // -99.9 no está en el mapa: el localizador devuelve null (fuera de cobertura).
    ]);
    $policy = new RouteDepartmentPolicy($locator);

    $scope = $policy->guard([
        ClientStopLocation::of('shp-1', -16.1, -68.1),
        ClientStopLocation::of('shp-unknown', 0.0, 0.0),
    ]);

    // La parada desconocida no dispara la regla; el alcance queda en el único departamento conocido.
    expect($scope->isResolved())->toBeTrue()
        ->and($scope->departmentId)->toBe('dep-lp');
});
