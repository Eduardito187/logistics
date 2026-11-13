<?php

declare(strict_types=1);

use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;

/**
 * Cobertura del controller HTTP GetOutboxMessageController por su ruta real de la puerta `app`
 * (GET /api/v1/app/outbox/messages/{id}). Ejercita el controller y el QueryBus, con sus dos ramas:
 * mensaje existente (200) e inexistente (404 problem+json). Se desactiva el middleware de puerta.
 * Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('devuelve el detalle de un mensaje existente (200)', function (): void {
    $record = OutboxMessageRecord::factory()->failed()->create();

    $this->getJson('/api/v1/app/outbox/messages/'.$record->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', (string) $record->getKey())
        ->assertJsonStructure(['data' => ['id', 'event_id', 'status', 'attempts', 'payload', 'last_error']]);
});

it('responde 404 problem+json cuando el mensaje no existe', function (): void {
    $this->getJson('/api/v1/app/outbox/messages/0192f000-0000-7000-8000-000000000000')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 404);
});
