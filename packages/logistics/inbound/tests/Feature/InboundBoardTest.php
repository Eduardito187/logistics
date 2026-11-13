<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnRecord;
use Logistics\Inbound\Presentation\Filament\Pages\InboundBoard;

/**
 * Cobertura del cockpit "Inbound board" (Page de Filament). Montarlo arma los segmentos por estado desde
 * el lado de lectura CQRS y, al renderizar la vista, construye las acciones inline (schedule/arrival/label)
 * y mapea cada ASN a su fila legible (nombre de seller/almacén, nunca UUID). Contra logistics_test.
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

it('renderiza el tablero vacío', function (): void {
    Livewire::test(InboundBoard::class)->assertOk();
});

it('renderiza el tablero con ASNs en varios estados y construye sus filas y acciones', function (): void {
    AsnRecord::factory()->create(['status' => 'announced']);
    AsnRecord::factory()->scheduled()->create();
    AsnRecord::factory()->create(['status' => 'arrived']);
    AsnRecord::factory()->create(['status' => 'received']);

    Livewire::test(InboundBoard::class)->assertOk();
});
