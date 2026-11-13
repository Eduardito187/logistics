<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Ejercita SetSettingController por la puerta integrations: publica una nueva versión de una clave
 * despachando el comando al bus CQRS y responde 202 con el eco de lo aceptado. Luego se comprueba, por el
 * QueryBus, que el valor vigente quedó persistido. Contra PostgreSQL (base logistics_test).
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

it('publica una versión nueva y responde 202 con el eco', function (): void {
    $this->postJson('/api/v1/integrations/settings', [
        'key' => 'coverage.http_set_sample',
        'value' => ['days' => 5],
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.key', 'coverage.http_set_sample');

    expect(app(QueryBus::class)->ask(new GetSettingQuery('coverage.http_set_sample')))
        ->toMatchArray(['days' => 5]);
});

it('acepta effective_from y created_by y los pasa al comando', function (): void {
    $this->postJson('/api/v1/integrations/settings', [
        'key' => 'coverage.http_set_audited',
        'value' => ['enabled' => true],
        'effective_from' => now()->toIso8601String(),
        'created_by' => 'svc-logistics',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);

    expect(app(QueryBus::class)->ask(new GetSettingQuery('coverage.http_set_audited')))
        ->toMatchArray(['enabled' => true]);
});
