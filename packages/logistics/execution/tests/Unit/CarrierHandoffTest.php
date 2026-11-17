<?php

declare(strict_types=1);

use Logistics\Execution\Application\Command\RecordCarrierHandoff\RecordCarrierHandoff;
use Logistics\Execution\Application\Command\RecordCarrierHandoff\RecordCarrierHandoffHandler;
use Logistics\Execution\Application\Contract\ShipmentItineraryReader;
use Logistics\Execution\Application\ReadModel\ItineraryLeg;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;

// HANDOFF a la transportadora: NUESTRO lado deja la carga en el almacén del tercero (carrier_hub). Cierra el
// tramo de flota propia (execution.shipment_leg_closed) y declara el pase (execution.shipment_handed_to_carrier);
// NO marca la entrega como Delivered: el envío sigue en manos de la transportadora. Idempotente por clave.

const HOF_SHIP = 'a1111111-1111-7111-8111-111111111111';
const HOF_VEH = 'b2222222-2222-7222-8222-222222222222';
const HOF_HUB = 'c3333333-3333-7333-8333-333333333333';
const HOF_CARRIER = 'd4444444-4444-7444-8444-444444444444';

/** Entrega abierta (LOADED) de ejemplo, sin escaneos todavía. */
function hofLoadedDelivery(): Delivery
{
    return Delivery::reconstitute(
        DeliveryId::generate(),
        HOF_SHIP,
        HOF_VEH,
        DeliveryStatus::Loaded,
        [],
        null,
        null,
    );
}

function hofDevice(): DeviceContext
{
    return DeviceContext::of('device-01', 'user-07');
}

/** @param list<\Logistics\Shared\Domain\Event\DomainEvent> $events */
function hofNames(array $events): array
{
    return array_map(static fn ($event): string => $event->eventName(), $events);
}

it('cierra el tramo propio y declara el pase al tercero, sin entregar', function (): void {
    $delivery = hofLoadedDelivery();

    $delivery->recordCarrierHandoff(
        HOF_HUB,
        HOF_CARRIER,
        2,
        GeoPoint::of(-17.78, -63.18),
        hofDevice(),
        new DateTimeImmutable('2026-11-17T12:00:00+00:00'),
        'idem-handoff-1',
    );

    $events = $delivery->releaseEvents();

    expect($delivery->status())->toBe(DeliveryStatus::Loaded)
        ->and(hofNames($events))->toContain('execution.shipment_leg_closed')
        ->and(hofNames($events))->toContain('execution.shipment_handed_to_carrier')
        ->and(hofNames($events))->not->toContain('execution.shipment_delivered')
        // Escaneo inmutable del handoff (append-only).
        ->and($delivery->scans())->toHaveCount(1)
        ->and($delivery->scans()[0]->type)->toBe(ScanType::Handoff);
});

it('es idempotente por idempotencyKey: el reintento no duplica hechos ni escaneos', function (): void {
    $delivery = hofLoadedDelivery();

    $delivery->recordCarrierHandoff(HOF_HUB, null, 1, null, hofDevice(), new DateTimeImmutable('2026-11-17T12:00:00+00:00'), 'idem-handoff-2');
    $delivery->releaseEvents();

    $delivery->recordCarrierHandoff(HOF_HUB, null, 1, null, hofDevice(), new DateTimeImmutable('2026-11-17T12:05:00+00:00'), 'idem-handoff-2');

    expect($delivery->releaseEvents())->toBe([])
        ->and($delivery->scans())->toHaveCount(1)
        ->and($delivery->status())->toBe(DeliveryStatus::Loaded);
});

it('sobre una entrega ya entregada es un no-op (no reabre ni reemite)', function (): void {
    $delivery = Delivery::reconstitute(
        DeliveryId::generate(),
        HOF_SHIP,
        HOF_VEH,
        DeliveryStatus::Delivered,
        [],
        null,
        null,
    );

    $delivery->recordCarrierHandoff(HOF_HUB, HOF_CARRIER, 1, null, hofDevice(), new DateTimeImmutable('2026-11-17T12:00:00+00:00'), 'idem-handoff-3');

    expect($delivery->releaseEvents())->toBe([])
        ->and($delivery->status())->toBe(DeliveryStatus::Delivered)
        ->and($delivery->scans())->toBe([]);
});

it('el handler graba ambos hechos en el outbox y no entrega', function (): void {
    $delivery = hofLoadedDelivery();

    $repo = new class($delivery) implements DeliveryRepository
    {
        public int $saves = 0;

        public function __construct(private ?Delivery $delivery) {}

        public function nextIdentity(): DeliveryId
        {
            return DeliveryId::generate();
        }

        public function save(Delivery $delivery): void
        {
            $this->delivery = $delivery;
            $this->saves++;
        }

        public function findById(DeliveryId $id): ?Delivery
        {
            return $this->delivery;
        }

        public function findByShipmentId(string $shipmentId): ?Delivery
        {
            return $this->delivery;
        }

        public function findByVehicleId(string $vehicleId, ?int $limit = null, int $offset = 0, array $statuses = []): array
        {
            return [];
        }

        public function countByVehicleId(string $vehicleId, array $statuses = []): int
        {
            return 0;
        }
    };

    $outbox = new class implements OutboxStore
    {
        /** @var list<OutboxMessage> */
        public array $messages = [];

        public function append(OutboxMessage $message): void
        {
            $this->messages[] = $message;
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        }
    };

    // El itinerario resuelve el tramo de flota propia abierto (seq + transportadora) sin tocar la base.
    $itinerary = new class implements ShipmentItineraryReader
    {
        public function finalDestinationNodeId(string $shipmentId): ?string
        {
            return null;
        }

        public function isIntermediateHub(string $shipmentId, string $nodeId): bool
        {
            return false;
        }

        public function nextOpenLeg(string $shipmentId): ?ItineraryLeg
        {
            return new ItineraryLeg(2, null, HOF_HUB, 'own_fleet', HOF_CARRIER, 'in_transit');
        }
    };

    $handler = new RecordCarrierHandoffHandler($repo, new RecordDomainEvents($outbox, $clock), $itinerary);

    $handler->handle(new RecordCarrierHandoff(
        shipmentId: HOF_SHIP,
        carrierHubNodeId: HOF_HUB,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-11-17T12:00:00+00:00',
        idempotencyKey: 'idem-handoff-9',
    ));

    $names = array_map(static fn (OutboxMessage $message): string => $message->eventName(), $outbox->messages);

    expect($repo->saves)->toBe(1)
        ->and($names)->toContain('execution.shipment_leg_closed')
        ->and($names)->toContain('execution.shipment_handed_to_carrier')
        ->and($names)->not->toContain('execution.shipment_delivered')
        ->and($delivery->status())->toBe(DeliveryStatus::Loaded);
});
