<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryLotRecord;
use Logistics\Inventory\Presentation\Filament\Resources\StockOnHandResource\Pages\ListStockOnHand;
use Logistics\Inventory\Presentation\Filament\Resources\StockOnHandResource\Pages\ViewStockLot;

/**
 * Cobertura de la Resource Filament de stock on-hand (tablero de solo lectura). Montar la página de
 * listado ejecuta la definición de table() (columnas, filtros por dueño/modo); montar la vista de detalle
 * renderiza el infolist y ejerce los formateadores legibles (almacén/dueño/modo/costo) sobre un registro
 * real, cubriendo las ramas de lote propio (owned) y en consignación (FBD). Corre contra logistics_test;
 * skip sin PostgreSQL.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('renderiza el listado de stock on-hand', function (): void {
    InventoryLotRecord::factory()->create();

    Livewire::test(ListStockOnHand::class)->assertOk();
});

it('aplica el filtro por dueño propio y por consignación sin romper', function (): void {
    InventoryLotRecord::factory()->create();
    InventoryLotRecord::factory()->consignment('0192aaaa-bbbb-7ccc-8ddd-000000000001')->create();

    Livewire::test(ListStockOnHand::class)
        ->set('tableFilters.owner.value', 'owned')
        ->assertOk()
        ->set('tableFilters.owner.value', 'seller')
        ->assertOk()
        ->set('tableFilters.fulfillment_mode.value', 'fbd')
        ->assertOk();
});

it('renderiza la vista de detalle de un lote propio de Dismac', function (): void {
    $record = InventoryLotRecord::factory()->create([
        'fulfillment_mode' => 'owned',
        'owner_party_id' => null,
    ]);

    Livewire::test(ViewStockLot::class, ['record' => $record->getKey()])->assertOk();
});

it('renderiza la vista de detalle de un lote en consignación de un seller', function (): void {
    $record = InventoryLotRecord::factory()
        ->consignment('0192aaaa-bbbb-7ccc-8ddd-000000000001', 'SELLER-REF-9')
        ->create();

    Livewire::test(ViewStockLot::class, ['record' => $record->getKey()])->assertOk();
});
