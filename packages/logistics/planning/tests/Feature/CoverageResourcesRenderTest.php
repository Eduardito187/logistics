<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Planning\Presentation\Filament\PlannedStopResource\Pages\ListPlannedStops;
use Logistics\Planning\Presentation\Filament\PlannedStopResource\Pages\ViewPlannedStop;
use Logistics\Planning\Presentation\Filament\PlanResource\Pages\ListPlans;
use Logistics\Planning\Presentation\Filament\PlanResource\Pages\ViewPlan;

// Ejercita las páginas Livewire de los resources Filament de planificación (Presentation). Montar la
// página corre las definiciones de table()/infolist()/schema() y los constructores de las acciones de
// cabecera y de fila; con filas reales de cada estado se evalúan además los closures de formato,
// color y visibilidad por fila. No se cazan bugs: solo se ejercita el render real.

beforeEach(function (): void {
    // Los resources resuelven el panel actual para render; fuera de HTTP hay que fijarlo a mano.
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el listado de planes vacío', function (): void {
    Livewire::test(ListPlans::class)->assertOk();
});

it('renderiza el listado de planes con filas de cada estado', function (): void {
    // Un plan por estado para que la tabla evalúe formatStateUsing/color y las acciones de fila su
    // closure de visibilidad (publishPlan solo en Proposed; assign/reorder ocultos en Published).
    PlanRecordFactory::new()->withStops(2)->create();
    PlanRecordFactory::new()->proposed()->withStops(3)->create();
    PlanRecordFactory::new()->published()->withStops(2)->create();

    Livewire::test(ListPlans::class)->assertOk();
});

it('renderiza la vista de un plan propuesto con paradas', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(3)->create();

    Livewire::test(ViewPlan::class, ['record' => $plan->getKey()])->assertOk();
});

it('renderiza el listado de paradas vacío', function (): void {
    Livewire::test(ListPlannedStops::class)->assertOk();
});

it('renderiza el listado de paradas con filas', function (): void {
    PlanRecordFactory::new()->proposed()->withStops(4)->create();

    Livewire::test(ListPlannedStops::class)->assertOk();
});

it('renderiza la vista de una parada', function (): void {
    PlanRecordFactory::new()->proposed()->withStops(3)->create();
    /** @var PlannedStopRecord $stop */
    $stop = PlannedStopRecord::query()->firstOrFail();

    Livewire::test(ViewPlannedStop::class, ['record' => $stop->getKey()])->assertOk();
});

it('monta las acciones de cabecera del listado de planes', function (): void {
    // Montar la acción construye y renderiza su formulario: corre el schema (selects buscables,
    // date pickers, repeater de envíos) de generateProposal y autoPlanTheDay.
    Livewire::test(ListPlans::class)
        ->mountAction('generateProposal')
        ->assertOk();

    Livewire::test(ListPlans::class)
        ->mountAction('autoPlanTheDay')
        ->assertOk();
});

it('monta las acciones de fila sobre un plan propuesto', function (): void {
    // Un plan propuesto con paradas habilita assignShipment, reorderStops (fillForm) y publishPlan.
    $plan = PlanRecordFactory::new()->proposed()->withStops(3)->create();

    Livewire::test(ListPlans::class)
        ->mountTableAction('assignShipment', $plan->getKey())
        ->assertOk();

    Livewire::test(ListPlans::class)
        ->mountTableAction('reorderStops', $plan->getKey())
        ->assertOk();

    Livewire::test(ListPlans::class)
        ->mountTableAction('publishPlan', $plan->getKey())
        ->assertOk();

    expect(PlanRecord::query()->whereKey($plan->getKey())->exists())->toBeTrue();
});
