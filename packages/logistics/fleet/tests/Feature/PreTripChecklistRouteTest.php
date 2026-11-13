<?php

declare(strict_types=1);

use Logistics\Fleet\Application\Command\ConductPreTripChecklist\ConductPreTripChecklist;
use Logistics\Fleet\Application\Query\ListPreTripChecklists\ListPreTripChecklists;
use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * El checklist pre-salida por RUTA/VIAJE (pedido del app): se conduce con `route_id` opcional, la vista
 * lo devuelve junto con la placa y el nombre del chofer, y la lista se puede filtrar por ruta para saber
 * si ya tiene su checklist. Corre contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('conduce un checklist con route_id, lo persiste y la vista lo devuelve con placa y nombre del chofer', function (): void {
    $vehicle = VehicleFactory::new()->create(['plate' => 'ABC1234']);
    $driver = DriverFactory::new()->create(['name' => 'Juan Pérez']);
    $routeId = Uuid::generate()->value;

    app(CommandBus::class)->dispatch(new ConductPreTripChecklist(
        vehicleId: (string) $vehicle->getKey(),
        driverId: (string) $driver->getKey(),
        items: ['tires' => true, 'brakes' => true],
        performedOn: '2026-11-13',
        routeId: $routeId,
    ));

    /** @var list<PreTripChecklistView> $views */
    $views = app(QueryBus::class)->ask(new ListPreTripChecklists(vehicleId: (string) $vehicle->getKey()));

    expect($views)->toHaveCount(1)
        ->and($views[0]->routeId)->toBe($routeId)
        ->and($views[0]->plate)->toBe('ABC1234')
        ->and($views[0]->driverName)->toBe('Juan Pérez')
        ->and($views[0]->toArray())
        ->toMatchArray([
            'route_id' => $routeId,
            'plate' => 'ABC1234',
            'driver_name' => 'Juan Pérez',
        ]);
});

it('filtra la lista por route_id y solo devuelve los checklists de esa ruta', function (): void {
    $vehicle = VehicleFactory::new()->create();
    $driver = DriverFactory::new()->create();
    $routeA = Uuid::generate()->value;
    $routeB = Uuid::generate()->value;

    foreach ([$routeA, $routeB, null] as $route) {
        app(CommandBus::class)->dispatch(new ConductPreTripChecklist(
            vehicleId: (string) $vehicle->getKey(),
            driverId: (string) $driver->getKey(),
            items: ['tires' => true, 'brakes' => true],
            performedOn: '2026-11-13',
            routeId: $route,
        ));
    }

    /** @var list<PreTripChecklistView> $onlyA */
    $onlyA = app(QueryBus::class)->ask(new ListPreTripChecklists(routeId: $routeA));
    /** @var list<PreTripChecklistView> $all */
    $all = app(QueryBus::class)->ask(new ListPreTripChecklists(vehicleId: (string) $vehicle->getKey()));

    expect($all)->toHaveCount(3)
        ->and($onlyA)->toHaveCount(1)
        ->and($onlyA[0]->routeId)->toBe($routeA);
});
