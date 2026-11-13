<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Ejercita GetSettingController contra la ruta real de la puerta app: devuelve el valor vigente de una
 * clave y responde 404 problem+json cuando la clave no tiene ninguna versión vigente. Se autentica con
 * Sanctum y se desactiva solo el middleware de credenciales de la puerta, igual que los demás tests HTTP.
 * Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->withoutMiddleware([EnsureDeviceToken::class, AuthenticateServiceClient::class]);
    Sanctum::actingAs(User::factory()->create());
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('devuelve el valor vigente de una clave por la puerta app', function (): void {
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: 'coverage.default_service_days',
        value: ['days' => 3, 'cutoff' => '14:00'],
        effectiveFrom: null,
        createdBy: 'tester',
    ));

    $this->getJson('/api/v1/app/settings/coverage.default_service_days')
        ->assertOk()
        ->assertJsonPath('data.key', 'coverage.default_service_days')
        ->assertJsonPath('data.value.days', 3)
        ->assertJsonPath('data.value.cutoff', '14:00');
});

it('acepta el parámetro `at` para leer el valor a una fecha dada', function (): void {
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: 'coverage.at_sample',
        value: ['enabled' => true],
        effectiveFrom: null,
        createdBy: 'tester',
    ));

    $this->getJson('/api/v1/app/settings/coverage.at_sample?at='.now()->toIso8601String())
        ->assertOk()
        ->assertJsonPath('data.value.enabled', true);
});

it('responde 404 problem+json cuando la clave no tiene valor vigente', function (): void {
    $this->getJson('/api/v1/app/settings/coverage.nonexistent_rule')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('title', 'Setting not found');
});
