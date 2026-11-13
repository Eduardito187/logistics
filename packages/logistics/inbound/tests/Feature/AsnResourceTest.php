<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnRecord;
use Logistics\Inbound\Presentation\Filament\Resources\AsnResource\Pages\ListAsns;
use Logistics\Inbound\Presentation\Filament\Resources\AsnResource\Pages\ViewAsn;

/**
 * Cobertura de la presentación Filament del AsnResource (admin de ASNs). Renderizar el listado construye la
 * tabla (columnas con formato de seller/estado/cita, filtro de estado y acciones de fila con su visibilidad
 * por estado); el detalle ejecuta el infolist. Con ASNs en varios estados para recorrer las ramas de
 * statusColor/visible. Contra logistics_test.
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

it('renderiza el listado de ASNs con registros en varios estados', function (): void {
    $announced = AsnRecord::factory()->create(['status' => 'announced']);
    $scheduled = AsnRecord::factory()->scheduled()->create();
    $arrived = AsnRecord::factory()->create(['status' => 'arrived']);
    $received = AsnRecord::factory()->create(['status' => 'received']);
    $closed = AsnRecord::factory()->create(['status' => 'closed']);
    $cancelled = AsnRecord::factory()->create(['status' => 'cancelled']);

    Livewire::test(ListAsns::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$announced, $scheduled, $arrived, $received, $closed, $cancelled]);
});

it('filtra el listado por estado', function (): void {
    $scheduled = AsnRecord::factory()->scheduled()->create();
    $announced = AsnRecord::factory()->create(['status' => 'announced']);

    Livewire::test(ListAsns::class)
        ->filterTable('status', 'scheduled')
        ->assertCanSeeTableRecords([$scheduled])
        ->assertCanNotSeeTableRecords([$announced]);
});

it('renderiza el detalle de un ASN agendado (infolist)', function (): void {
    $record = AsnRecord::factory()->scheduled()->create();

    Livewire::test(ViewAsn::class, ['record' => $record->getKey()])->assertOk();
});

it('renderiza el detalle de un ASN sin cita (placeholder)', function (): void {
    $record = AsnRecord::factory()->create(['status' => 'announced']);

    Livewire::test(ViewAsn::class, ['record' => $record->getKey()])->assertOk();
});
