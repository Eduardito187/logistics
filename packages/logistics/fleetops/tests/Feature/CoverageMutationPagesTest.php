<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\FuelPriceFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\VehicleProfileFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\VehicleProfileRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\EditFuelPrice;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\ListFuelPrices;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\EditVehicleProfile;

/**
 * Cobertura de las MUTACIONES de las páginas Filament de FleetOps (Patrón A del brief): guardar el Edit
 * ejecuta handleRecordUpdate(), que no escribe el record con Eloquent sino que re-despacha el comando CQRS
 * (UpdateFuelPrice / DefineVehicleProfile). También se ejercitan las acciones de fila del recurso de
 * precios (activar/desactivar/eliminar), cuyos closures despachan sus comandos. No caza bugs: recorre el
 * camino real de Presentation contra logistics_test.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('guarda la edición de un precio de combustible re-despachando el comando CQRS', function (): void {
    $record = FuelPriceFactory::new()->forFuelType('diesel')->priced(372)->effectiveFrom('2026-01-01')->create();

    Livewire::test(EditFuelPrice::class, ['record' => $record->getKey()])
        ->assertOk()
        ->fillForm([
            'price_bs' => '4.00',
            'currency' => 'BOB',
            'effective_from' => '2026-02-01',
            'source' => 'ANH',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((int) FuelPriceRecord::query()->whereKey($record->getKey())->value('price_cents'))->toBe(400);
});

it('guarda la edición del perfil técnico re-despachando DefineVehicleProfile', function (): void {
    $record = VehicleProfileFactory::new()->create(['brand' => 'Volvo']);

    Livewire::test(EditVehicleProfile::class, ['record' => $record->getKey()])
        ->assertOk()
        ->fillForm([
            'brand' => 'Scania',
            'model' => 'R450',
            'year' => '2023',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(VehicleProfileRecord::query()->whereKey($record->getKey())->value('brand'))->toBe('Scania');
});

it('activa un precio de combustible desde la acción de fila del recurso', function (): void {
    $record = FuelPriceFactory::new()->create(['active' => false]);

    Livewire::test(ListFuelPrices::class)
        ->callTableAction('activate', $record->getKey());

    expect((bool) FuelPriceRecord::query()->whereKey($record->getKey())->value('active'))->toBeTrue();
});

it('desactiva un precio de combustible desde la acción de fila del recurso', function (): void {
    $record = FuelPriceFactory::new()->create(['active' => true]);

    Livewire::test(ListFuelPrices::class)
        ->callTableAction('deactivate', $record->getKey());

    expect((bool) FuelPriceRecord::query()->whereKey($record->getKey())->value('active'))->toBeFalse();
});

it('elimina un precio de combustible desde la acción de fila del recurso', function (): void {
    $record = FuelPriceFactory::new()->create();
    $id = $record->getKey();

    Livewire::test(ListFuelPrices::class)
        ->callTableAction('delete', $id);

    expect(FuelPriceRecord::query()->whereKey($id)->exists())->toBeFalse();
});
