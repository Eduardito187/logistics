<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource\Pages\ListDeliveries;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;

/**
 * Cobertura de las ACCIONES del admin de entregas (DeliveryResource) que el render del listado no
 * ejecuta: montar cada acción corre su schema() —selects de bulto/nodo, campos de contexto— que es
 * justamente el grueso de código no cubierto por el simple render de la tabla. No caza bugs: ejercita
 * el código real de los formularios de las acciones operativas, tolerando que falten datos.
 *
 * Las acciones de fila (carga, descarga, recepción en nodo, prueba de entrega, fallo) viven dentro de un
 * ActionGroup; Filament las resuelve por nombre igual (aplana los grupos), así que mountTableAction las
 * monta. No se DESPACHA el comando: el select de bulto es buscable y el helper de test de Filament v4 no
 * precarga su valor, de modo que el efecto transaccional ya queda cubierto por los endpoints HTTP del
 * contexto (CoverageHttpLifecycleTest), no por estas acciones de panel.
 */
beforeEach(function (): void {
    // Los Resources/Pages resuelven el panel actual para render; en pruebas se fija a mano.
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea una entrega cuyo pedido tiene un bulto real, para que el select de bulto acotado al pedido
 * (unitSelectFor) recorra su rama con opciones y la etiqueta humana del bulto.
 */
function coverageDeliveryWithUnit(): DeliveryRecord
{
    $unit = PhysicalUnitRecord::factory()->create();

    return DeliveryRecord::factory()->create([
        'shipment_id' => $unit->shipment_id,
    ]);
}

it('monta la accion de cabecera de escaneo de preparacion', function (): void {
    // La preparación es acción de cabecera del listado (primer escaneo del ciclo): montarla corre su
    // schema (selects buscables de pedido/vehículo/bulto + campos de contexto GPS/usuario/hora).
    Livewire::test(ListDeliveries::class)
        ->mountAction('recordPreparationScan')
        ->assertOk();
});

it('monta la accion de carga sobre una entrega', function (): void {
    $delivery = coverageDeliveryWithUnit();

    Livewire::test(ListDeliveries::class)
        ->mountTableAction('recordLoadScan', $delivery->id)
        ->assertOk();
});

it('monta la accion de descarga sobre una entrega', function (): void {
    $delivery = coverageDeliveryWithUnit();

    Livewire::test(ListDeliveries::class)
        ->mountTableAction('recordUnloadScan', $delivery->id)
        ->assertOk();
});

it('monta la accion de recepcion en nodo sobre una entrega', function (): void {
    $delivery = coverageDeliveryWithUnit();

    Livewire::test(ListDeliveries::class)
        ->mountTableAction('recordNodeReception', $delivery->id)
        ->assertOk();
});

it('monta la accion de prueba de entrega sobre una entrega', function (): void {
    $delivery = coverageDeliveryWithUnit();

    Livewire::test(ListDeliveries::class)
        ->mountTableAction('recordProofOfDelivery', $delivery->id)
        ->assertOk();
});

it('monta la accion de entrega fallida sobre una entrega', function (): void {
    $delivery = coverageDeliveryWithUnit();

    Livewire::test(ListDeliveries::class)
        ->mountTableAction('recordFailedDelivery', $delivery->id)
        ->assertOk();
});
