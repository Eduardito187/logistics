<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource\Pages\ListOutboxMessages;
use Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource\Pages\ViewOutboxMessage;

/**
 * Cobertura de la capa Presentation del outbox: montar las páginas Livewire ejecuta las definiciones de
 * table()/infolist(), el badge de navegación (vía GetOutboxStats por el QueryBus) y los formateadores de
 * estado/payload, con mensajes reales en los cuatro estados. Patrón A del brief de cobertura. El panel
 * exige rol Admin (trait AdminOnly del Resource), así que el acting user es administrador.
 */
beforeEach(function (): void {
    // Los Resources/Pages resuelven el panel actual al renderizar; fuera de HTTP hay que fijarlo a mano.
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el listado del outbox con mensajes en los cuatro estados', function (): void {
    OutboxMessageRecord::factory()->count(2)->create();
    OutboxMessageRecord::factory()->dispatched()->create();
    OutboxMessageRecord::factory()->failed()->create();
    OutboxMessageRecord::factory()->deadLettered()->create();

    Livewire::test(ListOutboxMessages::class)->assertOk();
});

it('renderiza el detalle de un mensaje despachado del outbox', function (): void {
    $record = OutboxMessageRecord::factory()->dispatched()->create();

    Livewire::test(ViewOutboxMessage::class, ['record' => $record->getKey()])->assertOk();
});

it('renderiza el detalle de un mensaje en dead-letter con su último error', function (): void {
    $record = OutboxMessageRecord::factory()->deadLettered()->create();

    Livewire::test(ViewOutboxMessage::class, ['record' => $record->getKey()])->assertOk();
});
