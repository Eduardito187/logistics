<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Execution\Domain\Event\ShipmentDelivered;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Contract\DeliveredAtBackfill;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Outbox\ProjectExecutionTracking;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Fase 0 (calificación): `delivered_at` lo fija la proyección de la entrega y el backfill lo completa de
 * forma idempotente; el enlace público vence a las `tracking.public_link_ttl_hours` (default 72 h) contadas
 * DESDE LA ENTREGA y responde "enlace vencido" (410) en vez de datos. Corre contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Lleva un pedido hasta "en camino" por los comandos reales y devuelve el id del envío ligado. */
function ttlTrackingOnTheWay(string $orderReference): string
{
    $bus = app(CommandBus::class);
    $shipment = Uuid::generate()->value;

    $bus->dispatch(new OpenTracking(orderReference: $orderReference));
    $bus->dispatch(new BindShipmentToTracking(orderReference: $orderReference, shipmentId: $shipment));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $orderReference));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::InProgress, orderReference: $orderReference));
    $bus->dispatch(new ProjectExecutionEvent('execution.shipment_loaded', $shipment));
    $bus->dispatch(new ProjectExecutionEvent('execution.shipment_departed', $shipment));

    return $shipment;
}

/**
 * Inserta un mensaje del outbox mínimo (fuente del backfill).
 *
 * @param array<string, mixed> $payload
 */
function ttlOutboxRow(string $eventName, array $payload, string $createdAt): void
{
    DB::table('outbox_messages')->insert([
        'id' => Uuid::generate()->value,
        'event_id' => Uuid::generate()->value,
        'aggregate_type' => 'test.backfill',
        'aggregate_id' => Uuid::generate()->value,
        'event_name' => $eventName,
        'payload' => json_encode($payload),
        'status' => 'dispatched',
        'attempts' => 0,
        'available_at' => $createdAt,
        'created_at' => $createdAt,
    ]);
}

it('sets delivered_at from the delivery fact time and does not move it on replays', function (): void {
    $shipment = ttlTrackingOnTheWay('order-ttl-1');

    app(CommandBus::class)->dispatch(new ProjectExecutionEvent(
        'execution.shipment_delivered',
        $shipment,
        '2026-11-01T10:00:00+00:00',
    ));

    $record = TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect($record->status)->toBe(VisibleStatus::Delivered->value)
        ->and($record->delivered_at?->getTimestamp())->toBe(strtotime('2026-11-01T10:00:00+00:00'));

    // Reenvío at-least-once del outbox con otra hora: la fecha original se conserva.
    app(CommandBus::class)->dispatch(new ProjectExecutionEvent(
        'execution.shipment_delivered',
        $shipment,
        '2026-11-03T10:00:00+00:00',
    ));

    expect(TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail()->delivered_at?->getTimestamp())
        ->toBe(strtotime('2026-11-01T10:00:00+00:00'));
});

it('takes delivered_at from the outbox message when the reactor projects the delivery', function (): void {
    $shipment = ttlTrackingOnTheWay('order-ttl-2');
    $recordedAt = new DateTimeImmutable('2026-11-02T15:30:00+00:00');

    $message = OutboxMessage::fromDomainEvent(
        new ShipmentDelivered(Uuid::generate()->value, $shipment, 'QR', null, $recordedAt),
        'execution.delivery',
        $recordedAt,
    );

    app(ProjectExecutionTracking::class)->dispatch($message);

    expect(TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail()->delivered_at?->getTimestamp())
        ->toBe(strtotime('2026-11-02T15:30:00+00:00'));
});

it('backfills delivered_at for already delivered rows idempotently', function (): void {
    // Entregado sin fecha, con el hecho en el outbox ⇒ toma el created_at del mensaje.
    $fromOutbox = TrackingRecord::factory()->delivered()->create(['delivered_at' => null]);
    ttlOutboxRow('execution.shipment_delivered', ['shipment_id' => $fromOutbox->shipment_id], '2026-10-20 12:00:00+00');

    // Entregado sin ninguna fuente ⇒ último recurso: updated_at.
    $fallback = TrackingRecord::factory()->delivered()->create(['delivered_at' => null]);
    DB::table('tracking_shipments')->where('id', $fallback->id)->update(['updated_at' => '2026-10-21 08:00:00+00']);

    // No entregado ⇒ no se toca. Ya con fecha ⇒ no se pisa.
    $pending = TrackingRecord::factory()->withStatus(VisibleStatus::OnTheWay)->create(['delivered_at' => null]);
    $already = TrackingRecord::factory()->delivered()->create(['delivered_at' => '2026-10-01T09:00:00+00:00']);

    $first = app(DeliveredAtBackfill::class)->run();

    expect($first['outbox_delivered'])->toBeGreaterThanOrEqual(1)
        ->and($first['updated_at'])->toBeGreaterThanOrEqual(1);

    expect(TrackingRecord::query()->findOrFail($fromOutbox->id)->delivered_at?->getTimestamp())->toBe(strtotime('2026-10-20T12:00:00+00:00'))
        ->and(TrackingRecord::query()->findOrFail($fallback->id)->delivered_at?->getTimestamp())->toBe(strtotime('2026-10-21T08:00:00+00:00'))
        ->and(TrackingRecord::query()->findOrFail($pending->id)->delivered_at)->toBeNull()
        ->and(TrackingRecord::query()->findOrFail($already->id)->delivered_at?->getTimestamp())->toBe(strtotime('2026-10-01T09:00:00+00:00'));

    // Segunda corrida: nada que completar.
    expect(array_sum(app(DeliveredAtBackfill::class)->run()))->toBe(0);

    $this->artisan('logistics:tracking:backfill-delivered-at')->assertSuccessful();
});

it('serves a public link delivered within the TTL', function (): void {
    $token = TrackingToken::generate()->toString();
    TrackingRecord::factory()->delivered()->create([
        'token' => $token,
        'delivered_at' => now()->subHour(),
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    $this->getJson("/api/v1/channels/tracking/{$token}")->assertOk();
    $this->get("/t/{$token}")->assertOk();
});

it('answers link expired once the TTL from delivery has passed', function (): void {
    $token = TrackingToken::generate()->toString();
    TrackingRecord::factory()->delivered()->create([
        'token' => $token,
        // Default 72 h (sin configurar): entregado hace 100 h ⇒ vencido.
        'delivered_at' => now()->subHours(100),
    ]);

    $this->getJson("/api/v1/channels/tracking/{$token}")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'tracking_link_expired');

    $this->getJson("/api/v1/channels/tracking/{$token}/units")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'tracking_link_expired');

    $this->postJson("/t/{$token}/survey", ['rating' => 5])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'tracking_link_expired');

    $this->get("/t/{$token}")
        ->assertStatus(410)
        ->assertSee('ya venció', false)
        ->assertDontSee($token, false);
});

it('keeps the link of an undelivered order alive regardless of its age', function (): void {
    $token = TrackingToken::generate()->toString();
    $record = TrackingRecord::factory()->onTheWay()->create(['token' => $token, 'delivered_at' => null]);
    DB::table('tracking_shipments')->where('id', $record->id)->update(['created_at' => now()->subDays(30)]);

    $this->getJson("/api/v1/channels/tracking/{$token}")->assertOk();
});
