<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Logistics\Capacity\Presentation\Filament\Pages\DispatchCalendar;

/**
 * Cobertura de las acciones de cabecera del calendario de despacho que la ola de Filament dejó fuera:
 * "Edit rules" (publica una versión nueva de reglas) y "Activate slots" (activación por rango). Montar y
 * disparar cada acción con callAction ejecuta su fillForm, el schema del formulario (repeaters de
 * ventanas/reglas) y el cierre de la acción con sus helpers privados de normalización. Patrón A del brief.
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

it('publica una versión nueva de reglas vía la acción edit_rules', function (): void {
    // Sin overrides: el formulario se precarga con las reglas vigentes (currentRulesFormState), que son
    // válidas, y al enviar se valida el conjunto completo (CapacityRuleset::fromArray) y se despacha
    // SetSetting. Ejercita editRulesAction + formStateToRuleValue + rulesFormComponents y sus helpers.
    Livewire::test(DispatchCalendar::class)
        ->callAction('edit_rules')
        ->assertOk();
});

it('tolera la activación por rango sin municipios (rama de validación de la acción)', function (): void {
    Queue::fake();

    // all_municipalities = true pero sin municipios en el catálogo de coverage: la acción resuelve la
    // lista vacía y notifica "pick at least one" sin reventar. Ejercita activateSlots(),
    // formToActivationWindows() y los helpers toList()/toStringList().
    Livewire::test(DispatchCalendar::class)
        ->callAction('activate_slots', [
            'all_municipalities' => true,
            'municipality_ids' => [],
            'from' => '2026-03-02',
            'to' => '2026-03-10',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'windows' => [
                ['time_slot' => '08:00-12:00', 'unit' => 'deliveries', 'quantity' => 10],
            ],
        ])
        ->assertOk();
});
