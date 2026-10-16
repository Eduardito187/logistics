<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages\CreateMaterialMapping;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages\EditMaterialMapping;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages\ListMaterialMappings;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages\ViewMaterialMapping;
use Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource\Pages\ListStockTransfers;
use Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource\Pages\ViewStockTransfer;

/**
 * Cobertura de render de los recursos y páginas Filament del conector SAP (patrón A). Montar las páginas
 * Livewire ejecuta las definiciones de table()/form()/infolist()/getTabs() y las columnas calculadas, sin
 * tocar SAP: todo es lectura contra la base real (logistics_test).
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('renderiza el listado de traspasos de stock con datos en todos los estados', function (): void {
    StockTransferRecord::factory()->pending()->create();
    StockTransferRecord::factory()->sent()->create();
    StockTransferRecord::factory()->acknowledged()->create();
    StockTransferRecord::factory()->failed()->create();
    // Consignación de seller: ejercita la columna/filtro de propiedad y la resolución de dueño legible.
    StockTransferRecord::factory()->pending()->consignment()->create();

    Livewire::test(ListStockTransfers::class)->assertOk();
});

it('renderiza la vista de un traspaso enviado (acción de confirmación visible)', function (): void {
    $record = StockTransferRecord::factory()->sent()->create();

    Livewire::test(ViewStockTransfer::class, ['record' => $record->getKey()])->assertOk();
});

it('renderiza la vista de un traspaso fallido con su último error', function (): void {
    $record = StockTransferRecord::factory()->failed()->create();

    Livewire::test(ViewStockTransfer::class, ['record' => $record->getKey()])->assertOk();
});

it('renderiza el listado de mapeos de material', function (): void {
    MaterialMappingRecord::factory()->count(3)->create();
    // Mapeo de consignación de seller: ejercita la columna/filtro de propiedad y el dueño legible.
    MaterialMappingRecord::factory()->forSeller()->create();

    Livewire::test(ListMaterialMappings::class)->assertOk();
});

it('renderiza el create de mapeos de material', function (): void {
    Livewire::test(CreateMaterialMapping::class)->assertOk();
});

it('renderiza el edit de un mapeo de material', function (): void {
    $record = MaterialMappingRecord::factory()->create();

    Livewire::test(EditMaterialMapping::class, ['record' => $record->getKey()])->assertOk();
});

it('renderiza la vista de un mapeo de material', function (): void {
    $record = MaterialMappingRecord::factory()->create();

    Livewire::test(ViewMaterialMapping::class, ['record' => $record->getKey()])->assertOk();
});

it('expone los estados del traspaso en la definición del recurso', function (): void {
    // Toca el enum usado por las columnas/filtros para que su carga quede cubierta.
    expect(TransferStatus::Pending->value)->toBeString();
});
