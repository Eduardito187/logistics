<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Infrastructure\Persistence\Eloquent\SettingVersionRecord;
use Logistics\Settings\Presentation\Filament\Resources\IntegrationResource\Pages\ManageIntegrations;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages\CreateSettingVersion;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages\ListSettingVersions;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages\ViewSettingVersion;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de las Resources/Pages de Filament del contexto Configuración.
 *
 * Montar cada página Livewire ejecuta las definiciones de table()/form()/schema()/infolist() y, en el
 * panel de Integraciones, la construcción de las acciones de cabecera. List y Create no necesitan datos;
 * la vista de una versión sí: se publica una clave vía el bus CQRS. Corre contra PostgreSQL
 * (logistics_test); skip sin PostgreSQL.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el listado de versiones de configuración', function (): void {
    Livewire::test(ListSettingVersions::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el create de versión de configuración', function (): void {
    Livewire::test(CreateSettingVersion::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la vista de una versión de configuración', function (): void {
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: 'coverage.sample_rule',
        value: ['enabled' => true],
        effectiveFrom: null,
        createdBy: 'test',
    ));
    $record = SettingVersionRecord::query()->firstOrFail();

    Livewire::test(ViewSettingVersion::class, ['record' => $record->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el panel de gestión de integraciones', function (): void {
    Livewire::test(ManageIntegrations::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
