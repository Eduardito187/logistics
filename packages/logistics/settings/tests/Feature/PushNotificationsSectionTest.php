<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;
use Logistics\Settings\Presentation\Filament\Pages\Configuration;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Sección «Push notifications» de la pestaña Integrations (Firebase Cloud Messaging). Se ejercita por la
 * pantalla «Configuration» (Livewire): render, estado inicial y guardado que publica la clave FCM por el
 * bus CQRS cifrando el JSON de la cuenta de servicio. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    cache()->flush();
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('renderiza la sección de push con sus campos', function (): void {
    Livewire::withQueryParams(['section' => 'integrations-push'])
        ->test(Configuration::class)
        ->assertOk()
        ->assertSee(array_map(__(...), ['Push notifications', 'Service account']));
});

it('guarda la configuración de FCM cifrando el JSON de la cuenta de servicio', function (): void {
    $json = '{"type":"service_account","project_id":"dismac"}';

    Livewire::withQueryParams(['section' => 'integrations-push'])
        ->test(Configuration::class)
        ->set('data.enabled', true)
        ->set('data.project_id', 'dismac-logistics')
        ->set('data.timeout', 15)
        ->set('data.credentials_path', 'storage/app/firebase/service-account.json')
        ->set('data.credentials_json', $json)
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified();

    $stored = app(QueryBus::class)->ask(new GetSettingQuery(IntegrationKeys::FCM));

    expect($stored['enabled'])->toBeTrue()
        ->and($stored['project_id'])->toBe('dismac-logistics')
        ->and($stored['timeout'])->toBe(15)
        ->and($stored['credentials_path'])->toBe('storage/app/firebase/service-account.json')
        ->and(Crypt::decryptString($stored['credentials_json']))->toBe($json);
});

it('guardar el JSON en blanco conserva el guardado antes', function (): void {
    $json = '{"type":"service_account","project_id":"dismac"}';

    $page = Livewire::withQueryParams(['section' => 'integrations-push'])
        ->test(Configuration::class)
        ->set('data.enabled', true)
        ->set('data.credentials_json', $json)
        ->call('save')
        ->assertHasNoErrors();

    $page->assertSet('data.credentials_json', '')
        ->set('data.project_id', 'dismac-updated')
        ->call('save')
        ->assertHasNoErrors();

    $again = app(QueryBus::class)->ask(new GetSettingQuery(IntegrationKeys::FCM));

    expect($again['project_id'])->toBe('dismac-updated')
        ->and(Crypt::decryptString($again['credentials_json']))->toBe($json);
});
