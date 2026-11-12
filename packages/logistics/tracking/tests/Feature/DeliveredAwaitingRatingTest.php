<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\MarkRatingRequested\MarkRatingRequested;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Query\ListDeliveredAwaitingRating\ListDeliveredAwaitingRating;
use Logistics\Tracking\Application\ReadModel\DeliveredAwaitingRatingView;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Ola 0 de la calificación: al proyectar la entrega, el seguimiento copia el email del cliente desde el
 * lado lectura de shipments (sin acoplarse a execution); ListDeliveredAwaitingRating lista los entregados
 * hace ≥ 1 h con email, sin invitación ni respuesta, y MarkRatingRequested los saca de la lista. El email
 * nunca aparece en la página pública ni en la API de canales. Corre contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Lleva el seguimiento de un envío existente hasta "en camino" por los comandos reales. */
function ratingTrackingOnTheWay(string $orderReference, string $shipmentId): void
{
    $bus = app(CommandBus::class);

    $bus->dispatch(new OpenTracking(orderReference: $orderReference));
    $bus->dispatch(new BindShipmentToTracking(orderReference: $orderReference, shipmentId: $shipmentId));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $orderReference));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::InProgress, orderReference: $orderReference));
    $bus->dispatch(new ProjectExecutionEvent('execution.shipment_loaded', $shipmentId));
    $bus->dispatch(new ProjectExecutionEvent('execution.shipment_departed', $shipmentId));
}

/**
 * Seguimiento entregado directo en el read model (para armar escenarios de la lista).
 *
 * @param array<string, mixed> $overrides
 */
function ratingDeliveredRow(array $overrides = []): TrackingRecord
{
    return TrackingRecord::factory()->delivered()->create(array_merge([
        'shipment_id' => TrackingId::generate()->toString(),
        'order_reference' => 'ORD-'.random_int(100000, 999999),
        'delivered_at' => now()->subHours(2),
        'customer_email' => 'cliente@example.com',
        'rating_requested_at' => null,
    ], $overrides));
}

/**
 * @return list<DeliveredAwaitingRatingView>
 */
function ratingCandidates(int $limit = 100): array
{
    /** @var list<DeliveredAwaitingRatingView> $views */
    $views = app(QueryBus::class)->ask(new ListDeliveredAwaitingRating(new DateTimeImmutable('-3 days'), $limit));

    return $views;
}

it('captures the normalized customer email from the shipments read side when the delivery is projected', function (): void {
    $shipment = ShipmentRecord::factory()->create(['customer_email' => '  Ana.Perez@Example.COM ']);
    ratingTrackingOnTheWay('order-rating-1', $shipment->id);

    app(CommandBus::class)->dispatch(new ProjectExecutionEvent(
        'execution.shipment_delivered',
        $shipment->id,
        now()->subHours(2)->toIso8601String(),
    ));

    $record = TrackingRecord::query()->where('shipment_id', $shipment->id)->firstOrFail();
    expect($record->status)->toBe(VisibleStatus::Delivered->value)
        ->and($record->customer_email)->toBe('ana.perez@example.com')
        ->and($record->toArray())->not->toHaveKey('customer_email');

    // Un reenvío del outbox no pisa el email capturado aunque la cabecera haya cambiado.
    DB::table('shipments')->where('id', $shipment->id)->update(['customer_email' => 'otro@example.com']);
    app(CommandBus::class)->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment->id));

    expect(TrackingRecord::query()->where('shipment_id', $shipment->id)->firstOrFail()->customer_email)
        ->toBe('ana.perez@example.com');
});

it('never exposes the customer email on the public tracking page or channel API', function (): void {
    $shipment = ShipmentRecord::factory()->create(['customer_email' => 'privado@example.com']);
    ratingTrackingOnTheWay('order-rating-2', $shipment->id);
    app(CommandBus::class)->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment->id));

    $token = TrackingRecord::query()->where('shipment_id', $shipment->id)->firstOrFail()->token;

    $this->getJson("/api/v1/channels/tracking/{$token}")
        ->assertOk()
        ->assertDontSee('privado@example.com', false);
    $this->get("/t/{$token}")
        ->assertOk()
        ->assertDontSee('privado@example.com', false);
});

it('leaves the email empty when the shipment has none or an invalid one', function (): void {
    $invalid = ShipmentRecord::factory()->create(['customer_email' => 'no-es-un-email']);
    ratingTrackingOnTheWay('order-rating-3', $invalid->id);
    app(CommandBus::class)->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $invalid->id));

    // Envío sin cabecera en shipments: la entrega se proyecta igual, sin email.
    $orphan = Uuid::generate()->value;
    ratingTrackingOnTheWay('order-rating-4', $orphan);
    app(CommandBus::class)->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $orphan));

    $invalidRecord = TrackingRecord::query()->where('shipment_id', $invalid->id)->firstOrFail();
    $orphanRecord = TrackingRecord::query()->where('shipment_id', $orphan)->firstOrFail();

    expect($invalidRecord->status)->toBe(VisibleStatus::Delivered->value)
        ->and($invalidRecord->customer_email)->toBeNull()
        ->and($orphanRecord->status)->toBe(VisibleStatus::Delivered->value)
        ->and($orphanRecord->customer_email)->toBeNull();
});

it('lists only deliveries older than one hour with an email, not yet requested nor surveyed', function (): void {
    $shipment = ShipmentRecord::factory()->create(['customer_name' => 'Ana Pérez']);
    $eligible = ratingDeliveredRow([
        'shipment_id' => $shipment->id,
        'order_reference' => 'ORD-ELIGIBLE',
        'delivered_at' => now()->subHours(3),
    ]);
    $older = ratingDeliveredRow(['delivered_at' => now()->subHours(5)]);

    ratingDeliveredRow(['delivered_at' => now()->subMinutes(30)]);
    ratingDeliveredRow(['delivered_at' => now()->subDays(5)]);
    ratingDeliveredRow(['customer_email' => null]);
    ratingDeliveredRow(['rating_requested_at' => now()->subMinutes(10)]);
    TrackingRecord::factory()->withStatus(VisibleStatus::Returned)->create([
        'delivered_at' => now()->subHours(2),
        'customer_email' => 'devuelto@example.com',
    ]);

    $surveyed = ratingDeliveredRow();
    DB::table('tracking_satisfaction_surveys')->insert([
        'id' => Uuid::generate()->value,
        'shipment_id' => $surveyed->shipment_id,
        'token' => $surveyed->token,
        'rating' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $views = ratingCandidates();

    // Los más viejos primero.
    expect(array_map(static fn (DeliveredAwaitingRatingView $view): string => $view->shipmentId, $views))
        ->toBe([$older->shipment_id, $eligible->shipment_id]);

    expect($views[1]->toArray())->toMatchArray([
        'shipment_id' => $shipment->id,
        'order_reference' => 'ORD-ELIGIBLE',
        'customer_name' => 'Ana Pérez',
        'customer_email' => 'cliente@example.com',
        'tracking_token' => $eligible->token,
        'tracking_path' => '/t/'.$eligible->token,
    ])->and(strtotime($views[1]->deliveredAt))->toBe($eligible->delivered_at?->getTimestamp());

    expect(ratingCandidates(1))->toHaveCount(1);
});

it('marks a delivery as rating requested idempotently and drops it from the list', function (): void {
    $row = ratingDeliveredRow();
    expect(ratingCandidates())->toHaveCount(1);

    $bus = app(CommandBus::class);
    $bus->dispatch(new MarkRatingRequested($row->shipment_id ?? '', '2026-11-12T10:00:00+00:00'));
    $bus->dispatch(new MarkRatingRequested($row->shipment_id ?? '', '2026-11-12T12:00:00+00:00'));

    expect(TrackingRecord::query()->findOrFail($row->id)->rating_requested_at?->getTimestamp())
        ->toBe(strtotime('2026-11-12T10:00:00+00:00'))
        ->and(ratingCandidates())->toBe([]);
});
