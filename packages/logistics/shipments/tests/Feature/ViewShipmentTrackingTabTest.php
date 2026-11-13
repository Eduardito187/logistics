<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages\ViewShipment;

/**
 * Pestaña "Seguimiento" del hub 360 de un envío (cockpit `.distrack-cockpit`): afirma que monta sin romper
 * y que, con una etiqueta emitida del pedido, aparece su preview visual (producto, código único de la
 * etiqueta y estado). Las etiquetas visibles se afirman con `__()` —no literales— porque el admin corre
 * en español. Se marca skip sin PostgreSQL para no romper la suite Unit (igual que el resto de renders).
 */
beforeEach(function (): void {
    // Fuera de HTTP hay que fijar el panel actual a mano para que los recursos resuelvan su contexto.
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la pestaña Seguimiento del envío sin romper', function (): void {
    $shipment = ShipmentRecord::factory()->create();

    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertOk()
        ->assertSee(__('Tracking'))
        ->assertSee(__('Current status'))
        ->assertSee(__('Emitted labels'))
        // Sin etiquetas: estado vacío elegante, no rompe.
        ->assertSee(__('No labels have been emitted for this order yet.'));
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('muestra el preview de una etiqueta emitida del pedido en la pestaña Seguimiento', function (): void {
    $orderRef = 'DM-260123456';
    $shipment = ShipmentRecord::factory()->create(['order_reference' => $orderRef]);

    $label = EmittedLabelRecord::factory()->forOrder($orderRef)->create();

    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertOk()
        ->assertSee(__('Tracking'))
        ->assertSee(__('Emitted labels'))
        // El preview muestra el código único de la etiqueta y su estado (pendiente de impresión).
        ->assertSee($label->label_id)
        ->assertSee(__('Pending'))
        // Y NO el vacío, porque sí hay una etiqueta.
        ->assertDontSee(__('No labels have been emitted for this order yet.'));
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
