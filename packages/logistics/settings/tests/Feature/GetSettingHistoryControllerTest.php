<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Ejercita GetSettingHistoryController por la puerta app: devuelve el historial completo de una clave
 * (todas sus versiones) con el meta de conteo, y una lista vacía para una clave inexistente (no 404).
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

it('devuelve el historial de versiones de una clave con el conteo', function (): void {
    $bus = app(CommandBus::class);
    $bus->dispatch(new SetSettingCommand('coverage.history_sample', ['days' => 1], null, 'tester'));
    $bus->dispatch(new SetSettingCommand('coverage.history_sample', ['days' => 2], null, 'tester'));

    $this->getJson('/api/v1/app/settings/coverage.history_sample/history')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['key', 'count']])
        ->assertJsonPath('meta.key', 'coverage.history_sample')
        ->assertJsonPath('meta.count', 2);
});

it('devuelve una lista vacía para una clave sin versiones', function (): void {
    $this->getJson('/api/v1/app/settings/coverage.empty_history/history')
        ->assertOk()
        ->assertJsonPath('meta.count', 0)
        ->assertJsonPath('data', []);
});
