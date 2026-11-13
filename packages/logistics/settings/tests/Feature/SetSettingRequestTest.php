<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Ejercita la validación de SetSettingRequest a través de la ruta real de publicación (puerta
 * integrations): forma del payload (clave namespaced válida, `value` objeto, fecha y autor opcionales).
 * La regla de negocio la impone el dominio; aquí solo se comprueba la forma. Contra PostgreSQL
 * (base logistics_test).
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

it('rechaza payloads mal formados con errores de validación', function (array $payload, array $errors): void {
    $this->postJson('/api/v1/integrations/settings', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($errors);
})->with([
    'sin clave' => [['value' => ['a' => 1]], ['key']],
    'clave sin namespace' => [['key' => 'coverage', 'value' => ['a' => 1]], ['key']],
    'clave con mayúsculas' => [['key' => 'Coverage.default', 'value' => ['a' => 1]], ['key']],
    'sin value' => [['key' => 'coverage.sample'], ['value']],
    'value no es arreglo' => [['key' => 'coverage.sample', 'value' => 'plano'], ['value']],
    'fecha inválida' => [['key' => 'coverage.sample', 'value' => ['a' => 1], 'effective_from' => 'no-es-fecha'], ['effective_from']],
]);

it('acepta un payload mínimo válido', function (): void {
    $this->postJson('/api/v1/integrations/settings', [
        'key' => 'coverage.request_valid_sample',
        'value' => ['enabled' => true],
    ])->assertStatus(202);
});
