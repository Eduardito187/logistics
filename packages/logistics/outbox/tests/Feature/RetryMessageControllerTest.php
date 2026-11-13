<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;

/**
 * Cobertura del controller HTTP RetryMessageController por su ruta real de la puerta `app`
 * (POST /api/v1/app/outbox/messages/{id}/retry). Ejercita el controller y el CommandBus
 * (RetryMessageCommand), que reencola el mensaje dejándolo pendiente. Responde 202 Accepted.
 * Se desactiva el middleware de puerta. Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('reencola un mensaje fallido y responde 202', function (): void {
    $record = OutboxMessageRecord::factory()->failed()->create();

    $this->postJson('/api/v1/app/outbox/messages/'.$record->getKey().'/retry')
        ->assertStatus(202)
        ->assertJsonPath('data.id', (string) $record->getKey())
        ->assertJsonPath('data.status', 'pending');

    $record->refresh();

    expect($record->status)->toBe(OutboxStatus::Pending->value)
        ->and($record->attempts)->toBe(0);
});
