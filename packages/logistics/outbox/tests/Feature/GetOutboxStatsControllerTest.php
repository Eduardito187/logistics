<?php

declare(strict_types=1);

use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;

/**
 * Cobertura del controller HTTP GetOutboxStatsController por sus dos rutas reales: la puerta `app`
 * (GET /api/v1/app/outbox/messages/stats) y la puerta `integrations` (GET /api/v1/integrations/outbox/stats).
 * Ejercita el controller y el QueryBus que alimenta el tablero. Se desactiva el middleware de puerta.
 * Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('devuelve el conteo por estado en la puerta app (200)', function (): void {
    OutboxMessageRecord::factory()->count(2)->create();
    OutboxMessageRecord::factory()->count(1)->deadLettered()->create();

    $this->getJson('/api/v1/app/outbox/messages/stats')
        ->assertOk()
        ->assertJsonStructure(['data' => ['pending', 'failed', 'dispatched', 'dead_lettered', 'backlog', 'total']]);
});

it('devuelve el conteo por estado en la puerta integrations (200)', function (): void {
    OutboxMessageRecord::factory()->count(2)->failed()->create();

    $this->getJson('/api/v1/integrations/outbox/stats')
        ->assertOk()
        ->assertJsonPath('data.failed', 2)
        ->assertJsonStructure(['data' => ['pending', 'failed', 'dispatched', 'dead_lettered', 'backlog', 'total']]);
});
