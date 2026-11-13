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
 * Sección «Storage» de la pestaña Integrations (credenciales S3-compatibles). Se ejercita a través de la
 * pantalla «Configuration» (Livewire): render, estado inicial y guardado que publica la clave de
 * integración por el bus CQRS cifrando los secretos. Contra PostgreSQL (base logistics_test).
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

it('renderiza la sección de almacenamiento con el alcance global', function (): void {
    Livewire::withQueryParams(['section' => 'integrations-storage'])
        ->test(Configuration::class)
        ->assertOk()
        ->assertSee(array_map(__(...), ['Storage', 'Credentials', 'Bucket']));
});

it('guarda las credenciales S3 cifrando las llaves y publica la clave de integración', function (): void {
    Livewire::withQueryParams(['section' => 'integrations-storage'])
        ->test(Configuration::class)
        ->set('data.enabled', true)
        ->set('data.key', 'AKIAEXAMPLE')
        ->set('data.secret', 'super-secret')
        ->set('data.region', 'us-east-1')
        ->set('data.bucket', 'general-bucket')
        ->set('data.endpoint', 'https://minio.example.com')
        ->set('data.use_path_style_endpoint', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified();

    $stored = app(QueryBus::class)->ask(new GetSettingQuery(IntegrationKeys::STORAGE));

    expect($stored['enabled'])->toBeTrue()
        ->and($stored['region'])->toBe('us-east-1')
        ->and($stored['bucket'])->toBe('general-bucket')
        ->and($stored['endpoint'])->toBe('https://minio.example.com')
        ->and($stored['use_path_style_endpoint'])->toBeTrue()
        ->and(Crypt::decryptString($stored['key']))->toBe('AKIAEXAMPLE')
        ->and(Crypt::decryptString($stored['secret']))->toBe('super-secret');
});

it('guardar con las llaves en blanco conserva las guardadas antes', function (): void {
    $page = Livewire::withQueryParams(['section' => 'integrations-storage'])
        ->test(Configuration::class)
        ->set('data.enabled', true)
        ->set('data.key', 'AKIAFIRST')
        ->set('data.secret', 'secret-first')
        ->call('save')
        ->assertHasNoErrors();

    // El secreto nunca vuelve en claro al formulario; guardar en blanco conserva el cifrado anterior.
    $page->assertSet('data.key', '')
        ->assertSet('data.secret', '')
        ->set('data.region', 'eu-west-1')
        ->call('save')
        ->assertHasNoErrors();

    $again = app(QueryBus::class)->ask(new GetSettingQuery(IntegrationKeys::STORAGE));

    expect($again['region'])->toBe('eu-west-1')
        ->and(Crypt::decryptString($again['key']))->toBe('AKIAFIRST')
        ->and(Crypt::decryptString($again['secret']))->toBe('secret-first');
});
