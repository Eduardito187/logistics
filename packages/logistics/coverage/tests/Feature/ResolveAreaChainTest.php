<?php

declare(strict_types=1);

use Logistics\Coverage\Application\Command\DefineZone;
use Logistics\Coverage\Application\Command\UpdateZoneRules;
use Logistics\Coverage\Application\Query\GetEffectiveZoneRule;
use Logistics\Coverage\Application\Query\ResolveDepartmentForPoint;
use Logistics\Coverage\Application\Query\ResolveZoneForPoint;
use Logistics\Coverage\Application\ReadModel\LocatedAreaView;
use Logistics\Coverage\Application\ReadModel\ZoneRuleView;
use Logistics\Coverage\Application\ReadModel\ZoneView;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Resolución real punto → zona → ciudad → municipio → provincia → departamento contra PostGIS, y la
 * regla de cobertura por zona como configuración versionada (contexto settings). Necesita la base
 * logistics_test con PostGIS; se marca skip cuando no hay PostgreSQL.
 */

/**
 * Cuadrado cerrado de ~0.03° centrado en (lng, lat), en orden GeoJSON [lng, lat].
 *
 * @return array<int, array{0: float, 1: float}>
 */
function chainRing(float $lng, float $lat): array
{
    $half = 0.015;

    return [
        [$lng - $half, $lat - $half],
        [$lng + $half, $lat - $half],
        [$lng + $half, $lat + $half],
        [$lng - $half, $lat + $half],
        [$lng - $half, $lat - $half],
    ];
}

it('climbs the full administrative chain from a point', function (): void {
    /** @var GeoAreaRepository $areas */
    $areas = app(GeoAreaRepository::class);
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);

    // Departamento > Provincia > Municipio > Ciudad.
    $department = GeoArea::create($areas->nextIdentity(), 'La Paz', AdminLevel::Department, null);
    $areas->save($department);
    $province = GeoArea::create($areas->nextIdentity(), 'Pedro Domingo Murillo', AdminLevel::Province, $department->id());
    $areas->save($province);
    $municipality = GeoArea::create($areas->nextIdentity(), 'Nuestra Señora de La Paz', AdminLevel::Municipality, $province->id());
    $areas->save($municipality);
    $city = GeoArea::create($areas->nextIdentity(), 'La Paz', AdminLevel::City, $municipality->id());
    $areas->save($city);

    $rules = [
        'nodeId' => null,
        'services' => ['STD'],
        'baseMinutes' => 150,
        'productLimits' => [],
        'timeWindows' => [['start' => '08:00', 'end' => '18:00']],
        'serviceDays' => [1, 2, 3, 4, 5],
    ];
    $commands->dispatch(new DefineZone('Centro La Paz', $city->id()->toString(), chainRing(-68.133, -16.500), $rules));

    $located = $queries->ask(new ResolveDepartmentForPoint(-16.500, -68.133));
    expect($located)->toBeInstanceOf(LocatedAreaView::class);
    /** @var LocatedAreaView $located */
    expect($located->departmentName)->toBe('La Paz')
        ->and($located->provinceName)->toBe('Pedro Domingo Murillo')
        ->and($located->municipalityName)->toBe('Nuestra Señora de La Paz')
        ->and($located->cityName)->toBe('La Paz')
        ->and($located->departmentId)->toBe($department->id()->toString())
        ->and($located->provinceId)->toBe($province->id()->toString())
        ->and($located->municipalityId)->toBe($municipality->id()->toString())
        ->and($located->cityId)->toBe($city->id()->toString());

    // Fuera de toda zona: ubicación desconocida.
    expect($queries->ask(new ResolveDepartmentForPoint(0.0, 0.0)))->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);

it('tolerates a shorter chain that skips province and municipality', function (): void {
    /** @var GeoAreaRepository $areas */
    $areas = app(GeoAreaRepository::class);
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);

    $department = GeoArea::create($areas->nextIdentity(), 'Santa Cruz', AdminLevel::Department, null);
    $areas->save($department);
    $city = GeoArea::create($areas->nextIdentity(), 'Santa Cruz de la Sierra', AdminLevel::City, $department->id());
    $areas->save($city);

    $rules = ['nodeId' => null, 'services' => ['STD'], 'baseMinutes' => 120, 'productLimits' => [], 'timeWindows' => [], 'serviceDays' => [1, 2, 3, 4, 5]];
    $commands->dispatch(new DefineZone('Centro SCZ', $city->id()->toString(), chainRing(-63.182, -17.783), $rules));

    $located = $queries->ask(new ResolveDepartmentForPoint(-17.783, -63.182));
    expect($located)->toBeInstanceOf(LocatedAreaView::class);
    /** @var LocatedAreaView $located */
    expect($located->departmentName)->toBe('Santa Cruz')
        ->and($located->cityName)->toBe('Santa Cruz de la Sierra')
        ->and($located->provinceId)->toBeNull()
        ->and($located->municipalityId)->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);

it('versions the coverage rule per zone via settings', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);

    $rules = [
        'nodeId' => 'CD-SCZ',
        'services' => ['STD'],
        'baseMinutes' => 120,
        'productLimits' => [],
        'timeWindows' => [['start' => '08:00', 'end' => '18:00']],
        'serviceDays' => [1, 2, 3, 4, 5],
    ];
    $commands->dispatch(new DefineZone('Centro Versionado', null, chainRing(-63.182, -17.783), $rules));

    $zone = $queries->ask(new ResolveZoneForPoint(-17.783, -63.182));
    expect($zone)->toBeInstanceOf(ZoneView::class);
    /** @var ZoneView $zone */
    $zoneId = $zone->id;

    // La primera versión publicada en DefineZone es la vigente.
    $effective = $queries->ask(new GetEffectiveZoneRule($zoneId));
    expect($effective)->toBeInstanceOf(ZoneRuleView::class);
    /** @var ZoneRuleView $effective */
    expect($effective->baseMinutes)->toBe(120)
        ->and($effective->services)->toBe(['STD']);

    // Actualizar las reglas publica una versión nueva; la vigente cambia, la anterior no se pisa.
    $updated = ['nodeId' => 'CD-SCZ', 'services' => ['STD', 'EXPRESS'], 'baseMinutes' => 90, 'productLimits' => ['BULKY' => 3], 'timeWindows' => [], 'serviceDays' => [1, 2, 3, 4, 5, 6]];
    $commands->dispatch(new UpdateZoneRules($zoneId, $updated, 'tester'));

    $afterUpdate = $queries->ask(new GetEffectiveZoneRule($zoneId));
    expect($afterUpdate)->toBeInstanceOf(ZoneRuleView::class);
    /** @var ZoneRuleView $afterUpdate */
    expect($afterUpdate->baseMinutes)->toBe(90)
        ->and($afterUpdate->services)->toBe(['STD', 'EXPRESS'])
        ->and($afterUpdate->productLimits)->toBe(['BULKY' => 3]);

    // Una zona sin versión publicada devuelve null.
    expect($queries->ask(new GetEffectiveZoneRule('00000000-0000-0000-0000-000000000000')))->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);
