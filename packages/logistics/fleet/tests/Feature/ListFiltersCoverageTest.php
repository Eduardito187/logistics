<?php

declare(strict_types=1);

use Logistics\Fleet\Application\Query\ListDrivers\ListDrivers;
use Logistics\Fleet\Application\Query\ListHelpers\ListHelpers;
use Logistics\Fleet\Application\Query\ListVehicles\ListVehicles;
use Logistics\Fleet\Application\ReadModel\DriverView;
use Logistics\Fleet\Application\ReadModel\HelperView;
use Logistics\Fleet\Application\ReadModel\VehicleView;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\HelperFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura de las ramas de filtro de los listados (CQRS): `onlyActive` en choferes/ayudantes y `status`
 * en vehículos. Siembra filas reales y despacha por el QueryBus contra la base logistics_test.
 */
it('filtra los choferes por estado activo', function (): void {
    DriverFactory::new()->count(2)->create();
    DriverFactory::new()->inactive()->create();

    /** @var list<DriverView> $all */
    $all = app(QueryBus::class)->ask(new ListDrivers);
    /** @var list<DriverView> $active */
    $active = app(QueryBus::class)->ask(new ListDrivers(onlyActive: true));

    expect($all)->toHaveCount(3)
        ->and($active)->toHaveCount(2)
        ->and($active[0])->toBeInstanceOf(DriverView::class);
});

it('filtra los ayudantes por disponibilidad', function (): void {
    HelperFactory::new()->count(2)->create();
    HelperFactory::new()->inactive()->create();

    /** @var list<HelperView> $active */
    $active = app(QueryBus::class)->ask(new ListHelpers(onlyActive: true));

    expect($active)->toHaveCount(2)
        ->and($active[0])->toBeInstanceOf(HelperView::class);
});

it('filtra los vehículos por estado', function (): void {
    VehicleFactory::new()->count(2)->create();
    VehicleFactory::new()->maintenance()->create();

    /** @var list<VehicleView> $available */
    $available = app(QueryBus::class)->ask(new ListVehicles(status: 'available'));
    /** @var list<VehicleView> $maintenance */
    $maintenance = app(QueryBus::class)->ask(new ListVehicles(status: 'maintenance'));

    expect($available)->toHaveCount(2)
        ->and($maintenance)->toHaveCount(1)
        ->and($maintenance[0]->status)->toBe('maintenance');
});
