<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\SapConnector\Application\Command\DefineMaterialMapping\DefineMaterialMapping;
use Logistics\SapConnector\Application\Command\EnqueueStockTransfer\EnqueueStockTransfer;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource\Pages\ListStockTransfers;
use Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource\Pages\ViewStockTransfer;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de las acciones de cabecera del panel de reconciliación de traspasos a SAP (patrón A, pero
 * ejecutando los closures ->action()). El test de render solo monta las páginas; aquí se invocan las
 * acciones vía Livewire para recorrer la cadena CQRS detrás (EnqueueStockTransfer, DispatchPendingTransfers
 * y AcknowledgeStockTransfer), que es la parte no cubierta de ListStockTransfers y ViewStockTransfer.
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

it('encola un traspaso desde la acción de cabecera del listado', function (): void {
    MaterialMappingRecord::factory()->create([
        'sku' => 'SKU-ACT-ENQ',
        'sap_material' => '660001',
        'center' => 'C6001',
        'warehouse' => 'W60',
    ]);

    // Se monta el schema de la acción de cabecera (cobertura del form). El efecto se cubre despachando
    // EnqueueStockTransfer directamente: el form de una acción Filament v4 no es driveable por el helper de
    // test (callAction(data:) no llena su form), así que se ejercita el caso de uso por el CommandBus.
    Livewire::test(ListStockTransfers::class)
        ->mountAction('enqueueTransfer')
        ->assertOk();

    app(CommandBus::class)->dispatch(new EnqueueStockTransfer(
        '01a13700-0000-7000-8000-00000000e101',
        'SHP-ACT-1',
        'SKU-ACT-ENQ',
        2,
    ));

    expect(StockTransferRecord::query()->where('event_id', '01a13700-0000-7000-8000-00000000e101')->exists())
        ->toBeTrue();
});

it('corre el despacho de pendientes desde la acción de cabecera del listado', function (): void {
    StockTransferRecord::factory()->pending()->create();

    Livewire::test(ListStockTransfers::class)
        ->callAction('dispatchPending')
        ->assertOk();
});

it('confirma un traspaso enviado desde la acción de la vista de detalle', function (): void {
    $record = StockTransferRecord::factory()->sent()->create();

    Livewire::test(ViewStockTransfer::class, ['record' => $record->getKey()])
        ->callAction('acknowledge')
        ->assertOk();

    expect(StockTransferRecord::query()->whereKey($record->getKey())->value('status'))
        ->toBe(TransferStatus::Acknowledged->value);
});

it('corre el despacho de pendientes desde la vista de detalle', function (): void {
    $record = StockTransferRecord::factory()->failed()->create();

    Livewire::test(ViewStockTransfer::class, ['record' => $record->getKey()])
        ->callAction('dispatchPending')
        ->assertOk();
});

it('crea un mapeo de material por el comando de dominio (DefineMaterialMapping)', function (): void {
    // El render del form de alta ya lo cubre CoverageResourcesRenderTest; aquí se ejercita el alta real por
    // el caso de uso (handler + repositorio + mapper) despachando el comando por el CommandBus.
    app(CommandBus::class)->dispatch(new DefineMaterialMapping('SKU-ACT-CREATE', '550001', 'C5001', 'W50'));

    expect(MaterialMappingRecord::query()->where('sku', 'SKU-ACT-CREATE')->value('sap_material'))
        ->toBe('550001');
});

it('actualiza un mapeo de material por el comando de dominio (idempotente por SKU)', function (): void {
    MaterialMappingRecord::factory()->create([
        'sku' => 'SKU-ACT-EDIT',
        'sap_material' => '440001',
        'center' => 'C4001',
        'warehouse' => 'W40',
    ]);

    // Re-definir el mismo SKU actualiza el mapeo conservando su identidad (idempotencia del handler).
    app(CommandBus::class)->dispatch(new DefineMaterialMapping('SKU-ACT-EDIT', '440999', 'C4009', 'W49'));

    expect(MaterialMappingRecord::query()->where('sku', 'SKU-ACT-EDIT')->value('sap_material'))
        ->toBe('440999');
});
