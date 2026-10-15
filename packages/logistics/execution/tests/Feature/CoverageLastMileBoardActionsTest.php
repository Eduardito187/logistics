<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Execution\Presentation\Filament\Pages\LastMileBoard;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;

/**
 * Cobertura de las acciones inline del cockpit de última milla (LastMileBoard) que el render de la
 * página no ejecuta.
 *
 * Las acciones "Confirm delivery (POD)" y "Mark failed" se invocan por fila desde el blade pasando el
 * pedido como argumento. Montarlas con ese argumento corre su schema() —el bulto del pedido (unitSelect
 * con su etiqueta humana), la confirmación/foto o el motivo de fallo, y los campos de contexto— además
 * de argShipment(). No se DESPACHA el comando: el select de bulto es buscable y el helper de test de
 * Filament v4 no precarga su valor; el efecto transaccional ya queda cubierto por DeliveryResource y los
 * endpoints HTTP del contexto. No caza bugs: ejercita el código real de los formularios de la página.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('monta la accion de confirmar entrega con el pedido como argumento', function (): void {
    // Un bulto real del pedido para que unitSelect recorra su rama con opciones y la etiqueta humana.
    $unit = PhysicalUnitRecord::factory()->create();

    Livewire::test(LastMileBoard::class)
        ->mountAction('confirmDelivery', ['shipment' => $unit->shipment_id])
        ->assertOk();
});

it('monta la accion de marcar fallida con el pedido como argumento', function (): void {
    $unit = PhysicalUnitRecord::factory()->create();

    Livewire::test(LastMileBoard::class)
        ->mountAction('markFailed', ['shipment' => $unit->shipment_id])
        ->assertOk();
});

it('monta la accion de confirmar entrega sin bultos del pedido', function (): void {
    // Pedido sin bultos: unitSelect recorre la rama de opciones vacías (shipmentId presente, sin filas).
    Livewire::test(LastMileBoard::class)
        ->mountAction('confirmDelivery', ['shipment' => Uuid::generate()->value])
        ->assertOk();
});
