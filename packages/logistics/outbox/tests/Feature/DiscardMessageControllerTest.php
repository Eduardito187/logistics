<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;

/**
 * Cobertura del controller HTTP DiscardMessageController por su ruta real de la puerta `app`
 * (POST /api/v1/app/outbox/messages/{id}/discard). Ejercita el controller, su FormRequest (exige un
 * motivo) y el CommandBus (DiscardMessageCommand), que manda el mensaje a dead-letter. Responde 202.
 * Se desactiva el middleware de puerta. Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('descarta un mensaje fallido con su motivo y responde 202', function (): void {
    $record = OutboxMessageRecord::factory()->failed()->create();

    $this->postJson('/api/v1/app/outbox/messages/'.$record->getKey().'/discard', [
        'reason' => 'Evento huérfano: el pedido ya no existe',
    ])->assertStatus(202)
        ->assertJsonPath('data.id', (string) $record->getKey())
        ->assertJsonPath('data.status', 'dead_lettered');

    $record->refresh();

    expect($record->status)->toBe(OutboxStatus::DeadLettered->value)
        ->and($record->last_error)->toBe('Evento huérfano: el pedido ya no existe');
});

it('rechaza el descarte sin motivo con 422', function (): void {
    $record = OutboxMessageRecord::factory()->failed()->create();

    $this->postJson('/api/v1/app/outbox/messages/'.$record->getKey().'/discard', [])
        ->assertStatus(422);
});

it('rechaza un motivo demasiado corto con 422', function (): void {
    $record = OutboxMessageRecord::factory()->failed()->create();

    $this->postJson('/api/v1/app/outbox/messages/'.$record->getKey().'/discard', [
        'reason' => 'x',
    ])->assertStatus(422);
});
