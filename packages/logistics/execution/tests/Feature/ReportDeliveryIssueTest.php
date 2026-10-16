<?php

declare(strict_types=1);

use Logistics\Execution\Domain\ValueObject\DeliveryIssueType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryIssueRecord;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Endpoint POST /app/deliveries/{shipmentId}/issues: el chofer reporta un inconveniente de una entrega EN
 * CURSO (no la cierra). Se deja constancia inmutable (append-only), se emite el evento por el outbox y el
 * reenvío con la misma clave de idempotencia no duplica el hecho. Se omite el middleware de puerta para
 * ejercitar solo el contrato del controller, su FormRequest y la cadena CQRS detrás.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @return array<string, mixed>
 */
function issueBody(array $overrides = []): array
{
    return array_merge([
        'type' => DeliveryIssueType::Delay->value,
        'note' => 'Tráfico pesado en la avenida',
        'lat' => -16.5,
        'lng' => -68.15,
        'occurred_at' => '2026-10-16T10:00:00+00:00',
        'photo_path' => 'pod/issues/photo-1.jpg',
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'idempotency_key' => Uuid::generate()->value,
    ], $overrides);
}

it('registra un inconveniente de entrega y emite su evento por el outbox', function (): void {
    $shipment = Uuid::generate()->value;

    $this->postJson("/api/v1/app/deliveries/{$shipment}/issues", issueBody())
        ->assertStatus(202)
        ->assertJsonPath('data.shipment_id', $shipment)
        ->assertJsonPath('data.type', 'DELAY')
        ->assertJsonPath('data.status', 'REPORTED');

    // Quedó el hecho inmutable y su evento de dominio encolado (nada de I/O síncrono en el request).
    expect(DeliveryIssueRecord::query()->where('shipment_id', $shipment)->count())->toBe(1);
    expect(
        OutboxMessageRecord::query()->where('event_name', 'execution.delivery_issue_reported')->exists(),
    )->toBeTrue();
});

it('es idempotente: el reenvio con la misma clave no duplica el hecho', function (): void {
    $shipment = Uuid::generate()->value;
    $key = Uuid::generate()->value;

    $body = issueBody(['idempotency_key' => $key]);

    $this->postJson("/api/v1/app/deliveries/{$shipment}/issues", $body)->assertStatus(202);
    $this->postJson("/api/v1/app/deliveries/{$shipment}/issues", $body)->assertStatus(202);

    expect(DeliveryIssueRecord::query()->where('idempotency_key', $key)->count())->toBe(1);
});

it('rechaza un tipo de inconveniente desconocido', function (): void {
    $shipment = Uuid::generate()->value;

    $this->postJson("/api/v1/app/deliveries/{$shipment}/issues", issueBody(['type' => 'UNKNOWN']))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('type');
});

it('exige occurred_at: el hecho ocurrio en un momento concreto', function (): void {
    $shipment = Uuid::generate()->value;

    $body = issueBody();
    unset($body['occurred_at']);

    $this->postJson("/api/v1/app/deliveries/{$shipment}/issues", $body)
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('occurred_at');
});
