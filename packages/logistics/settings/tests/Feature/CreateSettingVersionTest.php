<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Settings\Infrastructure\Persistence\Eloquent\SettingVersionRecord;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages\CreateSettingVersion;

/**
 * Ejercita la creación de una versión de configuración desde el admin: la página no persiste con
 * Eloquent, despacha el comando SetSetting al bus CQRS (que asigna id, número de versión y fecha) y luego
 * recupera la versión recién creada para redirigir. Cubre handleRecordCreation y la decodificación del
 * valor JSON del formulario. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('publica una versión nueva despachando el comando y decodifica el valor JSON', function (): void {
    Livewire::test(CreateSettingVersion::class)
        ->fillForm([
            'key' => 'coverage.create_page_sample',
            'value' => '{"days": 3, "cutoff": "14:00"}',
            'created_by' => 'admin-ui',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = SettingVersionRecord::query()
        ->where('key', 'coverage.create_page_sample')
        ->orderByDesc('version')
        ->firstOrFail();

    expect($record->version)->toBe(1)
        ->and($record->value)->toBe(['days' => 3, 'cutoff' => '14:00'])
        ->and($record->created_by)->toBe('admin-ui');
});

it('rechaza un valor que no es JSON válido', function (): void {
    Livewire::test(CreateSettingVersion::class)
        ->fillForm([
            'key' => 'coverage.create_bad_json',
            'value' => 'no-es-json',
        ])
        ->call('create')
        ->assertHasFormErrors(['value']);
});
