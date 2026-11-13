<?php

declare(strict_types=1);

use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;

/**
 * Cobertura del controller HTTP RelayOutboxController por su ruta real de la puerta `integrations`
 * (POST /api/v1/integrations/outbox/relay). Ejercita el controller, su FormRequest (límite de lote
 * opcional) y la aplicación (RelayOutboxBatch), devolviendo el resumen de la corrida. Se desactiva el
 * middleware de puerta. Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('dispara una corrida del relay y devuelve el resumen (200)', function (): void {
    OutboxMessageRecord::factory()->count(3)->create();

    $this->postJson('/api/v1/integrations/outbox/relay', ['limit' => 50])
        ->assertOk()
        ->assertJsonStructure(['data' => ['pulled', 'dispatched', 'failed', 'dead_lettered']]);
});

it('usa el límite por defecto cuando no se envía (200)', function (): void {
    $this->postJson('/api/v1/integrations/outbox/relay', [])
        ->assertOk()
        ->assertJsonStructure(['data' => ['pulled', 'dispatched', 'failed', 'dead_lettered']]);
});

it('rechaza un límite fuera de rango con 422', function (): void {
    $this->postJson('/api/v1/integrations/outbox/relay', ['limit' => 100000])
        ->assertStatus(422);
});
