<?php

declare(strict_types=1);

use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\PushSubscriptionRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Contrato HTTP del endpoint público para guardar la suscripción Web Push (K-04).
 *
 * Se omite el middleware (rate-limit) para ejercitar solo el contrato. Corre contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

$tracked = function (): array {
    $shipment = ShipmentRecord::factory()->create();
    $token = TrackingToken::generate()->toString();
    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'token' => $token,
        'status' => 'ON_THE_WAY',
        'eta' => null,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    return ['shipment' => $shipment, 'token' => $token];
};

$payload = [
    'endpoint' => 'https://push.example.com/send/abc123',
    'keys' => [
        'p256dh' => 'BExampleP256dhKey_base64url',
        'auth' => 'ExampleAuthKey_base64url',
    ],
];

it('guarda la suscripción web push del navegador', function () use ($tracked, $payload): void {
    ['shipment' => $shipment, 'token' => $token] = $tracked();

    $this->postJson("/t/{$token}/push-subscriptions", $payload)
        ->assertStatus(201)
        ->assertJsonPath('data.subscribed', true);

    $sub = PushSubscriptionRecord::query()->where('shipment_id', $shipment->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->endpoint)->toBe($payload['endpoint']);
    expect($sub->p256dh)->toBe($payload['keys']['p256dh']);
});

it('es idempotente por endpoint (re-suscribir no duplica)', function () use ($tracked, $payload): void {
    ['token' => $token] = $tracked();

    $this->postJson("/t/{$token}/push-subscriptions", $payload)->assertStatus(201);
    $this->postJson("/t/{$token}/push-subscriptions", $payload)->assertStatus(201);

    expect(PushSubscriptionRecord::query()->where('endpoint', $payload['endpoint'])->count())->toBe(1);
});

it('rechaza con 404 un token inexistente', function () use ($payload): void {
    $this->postJson('/t/'.TrackingToken::generate()->toString().'/push-subscriptions', $payload)
        ->assertNotFound();
});

it('valida que lleguen endpoint y claves', function () use ($tracked): void {
    ['token' => $token] = $tracked();

    $this->postJson("/t/{$token}/push-subscriptions", ['endpoint' => 'https://push.example.com/x'])
        ->assertStatus(422);
});
