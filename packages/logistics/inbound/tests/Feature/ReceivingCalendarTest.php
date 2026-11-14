<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnRecord;
use Logistics\Inbound\Presentation\Filament\Pages\ReceivingCalendar;

/**
 * Cobertura del calendario de andén de recepción (Page de Filament): monta la semana desde el lado de
 * lectura CQRS y ejercita el movimiento de semana (anterior/siguiente/esta semana). Contra logistics_test.
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

it('renderiza la semana y ancla el weekStart al lunes', function (): void {
    $component = Livewire::test(ReceivingCalendar::class)->assertOk();

    $weekStart = $component->get('weekStart');
    expect($weekStart)->toBeString()
        ->and((int) date('N', (int) strtotime($weekStart)))->toBe(1);
});

it('renderiza la semana con una cita agendada', function (): void {
    AsnRecord::factory()->scheduled()->create();

    Livewire::test(ReceivingCalendar::class)->assertOk();
});

it('mueve la semana adelante, atrás y vuelve a esta semana', function (): void {
    $component = Livewire::test(ReceivingCalendar::class);
    $initial = $component->get('weekStart');

    $component->call('shiftWeek', 1);
    $next = $component->get('weekStart');
    expect($next)->not->toBe($initial);

    $component->call('shiftWeek', -1);
    expect($component->get('weekStart'))->toBe($initial);

    $component->call('goToThisWeek');
    expect($component->get('weekStart'))->toBe($initial);
});
