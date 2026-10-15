<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\Factory\CapacityBucketFactory;
use Logistics\Capacity\Presentation\Filament\Pages\DispatchCalendar;
use Logistics\Capacity\Presentation\Filament\Resources\BlackoutDayResource\Pages\ListBlackoutDays;
use Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource\Pages\ListCapacityBuckets;
use Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource\Pages\ViewCapacityBucket;

/**
 * Cobertura de la capa de presentación Filament del contexto capacity: el calendario de despacho
 * (Page) y los recursos de cupos y días bloqueados. Montar cada componente Livewire ejecuta las
 * definiciones de table()/form()/schema() y, en el calendario, el lado de lectura CQRS completo
 * (buildWeek → CheckDispatchDay/ListBucketsByDate/GetActiveHorizon) y los constructores de acciones.
 *
 * Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
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

it('renderiza el listado de cupos de capacidad', function (): void {
    Livewire::test(ListCapacityBuckets::class)->assertOk();
});

it('renderiza el listado de días bloqueados', function (): void {
    Livewire::test(ListBlackoutDays::class)->assertOk();
});

it('renderiza el detalle de un cupo de capacidad', function (): void {
    $bucket = CapacityBucketFactory::new()->create([
        'slot_date' => date('Y-m-d'),
        'time_slot' => '08:00-12:00',
    ]);

    Livewire::test(ViewCapacityBucket::class, ['record' => $bucket->id])->assertOk();
});

it('renderiza el calendario de despacho (vista base de la semana)', function (): void {
    Livewire::test(DispatchCalendar::class)->assertOk();
});

it('renderiza el calendario de despacho con cupos del día agregados por ventana', function (): void {
    // Un cupo en el día de hoy entra en la semana por defecto y ejercita aggregateWindows().
    CapacityBucketFactory::new()->create([
        'slot_date' => date('Y-m-d'),
        'time_slot' => '08:00-12:00',
    ]);

    Livewire::test(DispatchCalendar::class)->assertOk();
});

it('mueve la semana y vuelve a hoy en el calendario', function (): void {
    Livewire::test(DispatchCalendar::class)
        ->call('shiftWeek', 1)
        ->assertOk()
        ->call('shiftWeek', -2)
        ->assertOk()
        ->call('goToThisWeek')
        ->assertOk();
});

it('tolera un filtro de municipio en el calendario', function (): void {
    Livewire::test(DispatchCalendar::class)
        ->set('filters.municipality_id', '019299aa-0000-7000-8000-0000000000cc')
        ->assertOk();
});

it('intenta desbloquear un día sin bloqueo previo sin reventar', function (): void {
    // No hay bloqueo: el handler puede lanzar CapacityException, que la Page captura y notifica.
    Livewire::test(DispatchCalendar::class)
        ->call('unblockDay', '2026-03-02')
        ->assertOk();
});

it('genera el calendario desde las reglas vigentes vía la acción de cabecera', function (): void {
    Livewire::test(DispatchCalendar::class)
        ->callAction('generate', ['from' => '2026-03-02', 'days' => 2])
        ->assertOk();
});

it('bloquea un día global vía la acción de cabecera', function (): void {
    Livewire::test(DispatchCalendar::class)
        ->callAction('block_day', [
            'blackout_date' => '2026-03-02',
            'municipality_id' => null,
            'reason' => 'National holiday test',
        ])
        ->assertOk();
});
