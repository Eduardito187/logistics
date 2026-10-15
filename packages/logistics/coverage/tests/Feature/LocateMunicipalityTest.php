<?php

declare(strict_types=1);

use Logistics\Coverage\Application\Command\DefineZone;
use Logistics\Coverage\Application\Query\ListMunicipalities;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Contract\MunicipalityLocator;
use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\MunicipalityId;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Resolución real punto → municipio contra PostGIS y listado de municipios para selects del admin.
 * Necesita la base logistics_test con PostGIS; se marca skip cuando no hay PostgreSQL.
 */

/**
 * Cuadrado cerrado de ~0.03° centrado en (lng, lat), en orden GeoJSON [lng, lat].
 *
 * @return array<int, array{0: float, 1: float}>
 */
function municipalityRing(float $lng, float $lat): array
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

it('locates the municipality that contains a point and returns null outside', function (): void {
    /** @var GeoAreaRepository $areas */
    $areas = app(GeoAreaRepository::class);
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var MunicipalityLocator $locator */
    $locator = app(MunicipalityLocator::class);

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
    $commands->dispatch(new DefineZone('Centro La Paz', $city->id()->toString(), municipalityRing(-68.133, -16.500), $rules));

    // Punto dentro de la zona: resuelve al municipio ancestro.
    $located = $locator->locate(GeoPoint::of(-16.500, -68.133));
    expect($located)->toBeInstanceOf(MunicipalityId::class);
    /** @var MunicipalityId $located */
    expect($located->toString())->toBe($municipality->id()->toString());

    // Punto fuera de toda zona: ubicación desconocida.
    expect($locator->locate(GeoPoint::of(0.0, 0.0)))->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);

it('returns null when the containing zone has no municipality ancestor', function (): void {
    /** @var GeoAreaRepository $areas */
    $areas = app(GeoAreaRepository::class);
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var MunicipalityLocator $locator */
    $locator = app(MunicipalityLocator::class);

    // Cadena corta: la zona cuelga directo de la ciudad, que cuelga del departamento (sin municipio).
    $department = GeoArea::create($areas->nextIdentity(), 'Santa Cruz', AdminLevel::Department, null);
    $areas->save($department);
    $city = GeoArea::create($areas->nextIdentity(), 'Santa Cruz de la Sierra', AdminLevel::City, $department->id());
    $areas->save($city);

    $rules = ['nodeId' => null, 'services' => ['STD'], 'baseMinutes' => 120, 'productLimits' => [], 'timeWindows' => [], 'serviceDays' => [1, 2, 3, 4, 5]];
    $commands->dispatch(new DefineZone('Centro SCZ', $city->id()->toString(), municipalityRing(-63.182, -17.783), $rules));

    expect($locator->locate(GeoPoint::of(-17.783, -63.182)))->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);

it('lists municipalities with their department resolved and a readable label', function (): void {
    /** @var GeoAreaRepository $areas */
    $areas = app(GeoAreaRepository::class);
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);

    // La Paz: cadena completa con provincia intermedia.
    $lpz = GeoArea::create($areas->nextIdentity(), 'La Paz', AdminLevel::Department, null);
    $areas->save($lpz);
    $murillo = GeoArea::create($areas->nextIdentity(), 'Pedro Domingo Murillo', AdminLevel::Province, $lpz->id());
    $areas->save($murillo);
    $nuestraSenora = GeoArea::create($areas->nextIdentity(), 'Nuestra Señora de La Paz', AdminLevel::Municipality, $murillo->id());
    $areas->save($nuestraSenora);

    // Cochabamba: municipio colgado directo del departamento (cadena corta).
    $cbba = GeoArea::create($areas->nextIdentity(), 'Cochabamba', AdminLevel::Department, null);
    $areas->save($cbba);
    $cercado = GeoArea::create($areas->nextIdentity(), 'Cercado', AdminLevel::Municipality, $cbba->id());
    $areas->save($cercado);

    /** @var array<int, MunicipalityView> $list */
    $list = $queries->ask(new ListMunicipalities);

    $byId = [];
    foreach ($list as $view) {
        expect($view)->toBeInstanceOf(MunicipalityView::class);
        $byId[$view->id] = $view;
    }

    expect($byId)->toHaveKey($nuestraSenora->id()->toString())
        ->and($byId)->toHaveKey($cercado->id()->toString());

    $ns = $byId[$nuestraSenora->id()->toString()];
    expect($ns->name)->toBe('Nuestra Señora de La Paz')
        ->and($ns->departmentId)->toBe($lpz->id()->toString())
        ->and($ns->departmentName)->toBe('La Paz')
        ->and($ns->label())->toBe('Nuestra Señora de La Paz — La Paz');

    $ce = $byId[$cercado->id()->toString()];
    expect($ce->name)->toBe('Cercado')
        ->and($ce->departmentId)->toBe($cbba->id()->toString())
        ->and($ce->departmentName)->toBe('Cochabamba');

    // Ordenado por nombre ascendente.
    $names = array_map(static fn (MunicipalityView $v): string => $v->name, $list);
    $sorted = $names;
    sort($sorted);
    expect($names)->toBe($sorted);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);
