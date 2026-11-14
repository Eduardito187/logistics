<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;

/**
 * Cobertura del controller HTTP ListOutboxMessagesController por su ruta real de la puerta `app`
 * (GET /api/v1/app/outbox/messages). Ejercita el controller, su FormRequest (filtro por estado y
 * paginación) y el QueryBus. Se desactiva el middleware de puerta: la autenticación por token vive
 * en otros tests. Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('lista los mensajes del outbox paginados con su meta (200)', function (): void {
    OutboxMessageRecord::factory()->count(3)->create();

    $this->getJson('/api/v1/app/outbox/messages')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total']]);
});

it('filtra el listado por estado (200)', function (): void {
    OutboxMessageRecord::factory()->count(2)->failed()->create();
    OutboxMessageRecord::factory()->count(3)->dispatched()->create();

    $this->getJson('/api/v1/app/outbox/messages?status='.OutboxStatus::Failed->value.'&page=1&per_page=10')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('rechaza un estado inválido con 422', function (): void {
    $this->getJson('/api/v1/app/outbox/messages?status=not-a-status')
        ->assertStatus(422);
});

it('rechaza un per_page fuera de rango con 422', function (): void {
    $this->getJson('/api/v1/app/outbox/messages?per_page=5000')
        ->assertStatus(422);
});
