<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\DepartureFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\ExpenseFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\FuelPriceFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenanceOrderFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\OdometerReadingFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\VehicleProfileFactory;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages\CreateDeparture;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages\ListDepartures;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages\ViewDeparture;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages\ViewExpense;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\CreateFuelPrice;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\EditFuelPrice;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\ListFuelPrices;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource\Pages\CreateMaintenanceOrder;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource\Pages\ListMaintenanceOrders;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource\Pages\CreateMaintenancePlan;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource\Pages\ListMaintenancePlans;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages\CreateOdometerReading;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages\ListOdometerReadings;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages\ViewOdometerReading;
use Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource\Pages\CreateRefuel;
use Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource\Pages\ListRefuels;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\CreateVehicleProfile;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\EditVehicleProfile;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\ListVehicleProfiles;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\ViewVehicleProfile;

/**
 * Cobertura de render de los recursos Filament de FleetOps (Patrón A del brief): montar cada página
 * Livewire ejecuta las definiciones de table()/form()/schema()/infolist() sin datos imposibles. Las
 * páginas de Edit/View se montan con un registro sembrado por la fábrica del contexto. No caza bugs:
 * solo ejercita el código de Presentation.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

beforeEach(function (): void {
    // Los recursos resuelven el panel actual para render; en pruebas hay que fijarlo a mano.
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el listado y el create de cada recurso de FleetOps', function (string $list, string $create): void {
    Livewire::test($list)->assertOk();
    Livewire::test($create)->assertOk();
})->with([
    'VehicleProfile' => [
        ListVehicleProfiles::class,
        CreateVehicleProfile::class,
    ],
    'FuelPrice' => [
        ListFuelPrices::class,
        CreateFuelPrice::class,
    ],
    'Departure' => [
        ListDepartures::class,
        CreateDeparture::class,
    ],
    'Expense' => [
        ListExpenses::class,
        CreateExpense::class,
    ],
    'OdometerReading' => [
        ListOdometerReadings::class,
        CreateOdometerReading::class,
    ],
    'MaintenanceOrder' => [
        ListMaintenanceOrders::class,
        CreateMaintenanceOrder::class,
    ],
    'MaintenancePlan' => [
        ListMaintenancePlans::class,
        CreateMaintenancePlan::class,
    ],
    'Refuel' => [
        ListRefuels::class,
        CreateRefuel::class,
    ],
]);

it('renderiza el listado con datos sembrados (ejecuta columnas de la tabla)', function (): void {
    $vehicleId = '019299bb-0000-7000-8000-00000000a001';

    VehicleProfileFactory::new()->forVehicle($vehicleId)->create();
    FuelPriceFactory::new()->create();
    RefuelFactory::new()->forVehicle($vehicleId)->create();
    ExpenseFactory::new()->forVehicle($vehicleId)->create();
    OdometerReadingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-10 08:00:00'))->create();
    MaintenancePlanFactory::new()->forVehicle($vehicleId)->create();
    MaintenanceOrderFactory::new()->forVehicle($vehicleId)->completed(17_000)->create();
    DepartureFactory::new()->forVehicle($vehicleId)->returned(120)->create();

    Livewire::test(ListVehicleProfiles::class)->assertOk();
    Livewire::test(ListFuelPrices::class)->assertOk();
    Livewire::test(ListRefuels::class)->assertOk();
    Livewire::test(ListExpenses::class)->assertOk();
    Livewire::test(ListOdometerReadings::class)->assertOk();
    Livewire::test(ListMaintenancePlans::class)->assertOk();
    Livewire::test(ListMaintenanceOrders::class)->assertOk();
    Livewire::test(ListDepartures::class)->assertOk();
});

it('renderiza el edit del perfil de vehículo con un registro sembrado', function (): void {
    $record = VehicleProfileFactory::new()->create();

    Livewire::test(
        EditVehicleProfile::class,
        ['record' => $record->getKey()],
    )->assertOk();
});

it('renderiza el view del perfil de vehículo con un registro sembrado', function (): void {
    $record = VehicleProfileFactory::new()->create();

    Livewire::test(
        ViewVehicleProfile::class,
        ['record' => $record->getKey()],
    )->assertOk();
});

it('renderiza el edit del precio de combustible con un registro sembrado', function (): void {
    $record = FuelPriceFactory::new()->create();

    Livewire::test(
        EditFuelPrice::class,
        ['record' => $record->getKey()],
    )->assertOk();
});

it('renderiza el view de la salida con un registro sembrado', function (): void {
    $record = DepartureFactory::new()->returned(120)->create();

    Livewire::test(
        ViewDeparture::class,
        ['record' => $record->getKey()],
    )->assertOk();
});

it('renderiza el view del gasto con un registro sembrado', function (): void {
    $record = ExpenseFactory::new()->create();

    Livewire::test(
        ViewExpense::class,
        ['record' => $record->getKey()],
    )->assertOk();
});

it('renderiza el view de la lectura de odómetro con un registro sembrado', function (): void {
    $record = OdometerReadingFactory::new()->create();

    Livewire::test(
        ViewOdometerReading::class,
        ['record' => $record->getKey()],
    )->assertOk();
});
