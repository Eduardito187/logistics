<?php

declare(strict_types=1);

use Logistics\Execution\Application\Command\RecordStoreDrop\RecordStoreDrop;
use Logistics\Execution\Application\Command\RecordStoreDrop\RecordStoreDropHandler;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Application\Contract\ShipmentItineraryReader;
use Logistics\Execution\Application\Contract\ShipmentUnitIdsReader;
use Logistics\Execution\Application\Contract\TransferDestinationReader;
use Logistics\Execution\Application\ReadModel\ItineraryLeg;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Ownership;

// Correctitud multi-tramo (guarda "nodo intermedio ≠ entregado"): dejar la carga en un HUB INTERMEDIO del
// itinerario cierra el TRAMO (execution.shipment_leg_closed) y deja la entrega ABIERTA; solo el destino FINAL
// la cierra como entregada. Un envío de UN solo tramo (sin itinerario) se comporta como siempre.

const SDLG_SHIP = 'a1111111-1111-7111-8111-111111111111';
const SDLG_VEH = 'b2222222-2222-7222-8222-222222222222';
const SDLG_HUB = 'c3333333-3333-7333-8333-333333333333';
const SDLG_FINAL = 'e5555555-5555-7555-8555-555555555555';

/** Entrega abierta (LOADED) de ejemplo para el envío de prueba. */
function sdlgLoadedDelivery(): Delivery
{
    return Delivery::reconstitute(
        DeliveryId::generate(),
        SDLG_SHIP,
        SDLG_VEH,
        DeliveryStatus::Loaded,
        [],
        null,
        null,
    );
}

/** Repositorio de entregas en memoria (una por envío). */
function sdlgDeliveryRepo(Delivery $delivery): DeliveryRepository
{
    return new class($delivery) implements DeliveryRepository
    {
        public int $saves = 0;

        public function __construct(private Delivery $delivery) {}

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
}

/** Rutas en memoria: el envío no está en ninguna ruta (sin parada que cerrar). */
function sdlgRouteRepo(): VehicleRouteRepository
{
    return new class implements VehicleRouteRepository
    {
        public function nextIdentity(): RouteId
        {
            return RouteId::generate();
        }

        public function save(VehicleRoute $route): void {}

        public function findById(RouteId $id): ?VehicleRoute
        {
            return null;
        }

        public function findByVehicleAndDate(string $vehicleId, RouteDate $date): ?VehicleRoute
        {
            return null;
        }

        public function findOpenByVehicleId(string $vehicleId): ?VehicleRoute
        {
            return null;
        }

        public function findByShipmentId(string $shipmentId): ?VehicleRoute
        {
            return null;
        }
    };
}

function sdlgFulfillment(): ShipmentFulfillmentReader
{
    return new class implements ShipmentFulfillmentReader
    {
        public function forShipmentId(string $shipmentId): Ownership
        {
            return Ownership::dismacOwned();
        }
    };
}

/** @param list<string> $unitIds */
function sdlgUnits(array $unitIds): ShipmentUnitIdsReader
{
    return new class($unitIds) implements ShipmentUnitIdsReader
    {
        /** @param list<string> $unitIds */
        public function __construct(private array $unitIds) {}

        public function forShipmentIds(array $shipmentIds): array
        {
            return [SDLG_SHIP => $this->unitIds];
        }
    };
}

/** El envío es un traspaso con destino final `$destinationNodeId`. */
function sdlgTransfers(?string $destinationNodeId): TransferDestinationReader
{
    return new class($destinationNodeId) implements TransferDestinationReader
    {
        public function __construct(private ?string $destinationNodeId) {}

        public function transferDestinationNodeId(string $shipmentId): ?string
        {
            return $this->destinationNodeId;
        }
    };
}

/** Itinerario que trata `$intermediate` como hub intermedio (tramo abierto seq 1). */
function sdlgItinerary(?string $intermediate): ShipmentItineraryReader
{
    return new class($intermediate) implements ShipmentItineraryReader
    {
        public function __construct(private ?string $intermediate) {}

        public function finalDestinationNodeId(string $shipmentId): ?string
        {
            return SDLG_FINAL;
        }

        public function isIntermediateHub(string $shipmentId, string $nodeId): bool
        {
            return $this->intermediate !== null && strtolower($nodeId) === strtolower($this->intermediate);
        }

        public function nextOpenLeg(string $shipmentId): ?ItineraryLeg
        {
            return new ItineraryLeg(1, null, $this->intermediate, 'own_fleet', null, 'in_transit');
        }
    };
}

function sdlgOutbox(): OutboxStore
{
    return new class implements OutboxStore
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
}

function sdlgClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        }
    };
}

it('dejar la carga en un nodo INTERMEDIO cierra el tramo y NO entrega', function (): void {
    $delivery = sdlgLoadedDelivery();
    $outbox = sdlgOutbox();

    // El destino del traspaso COINCIDE con el nodo intermedio: sin la guarda, cerraría como entregado. Con la
    // guarda del itinerario (el nodo es un hub intermedio) solo cierra el tramo y el envío sigue abierto.
    $handler = new RecordStoreDropHandler(
        sdlgDeliveryRepo($delivery),
        sdlgRouteRepo(),
        sdlgFulfillment(),
        new RecordDomainEvents($outbox, sdlgClock()),
        sdlgUnits([]),
        sdlgTransfers(SDLG_HUB),
        sdlgItinerary(SDLG_HUB),
    );

    $handler->handle(new RecordStoreDrop(SDLG_SHIP, SDLG_HUB, '2026-11-17T11:00:00+00:00'));

    expect($delivery->status())->toBe(DeliveryStatus::Loaded)
        ->and(array_map(static fn ($m): string => $m->eventName(), $outbox->messages))->toContain('execution.shipment_leg_closed')
        ->and(array_map(static fn ($m): string => $m->eventName(), $outbox->messages))->not->toContain('execution.shipment_delivered');
});

it('dejar la carga en el destino FINAL de un traspaso de un solo tramo SÍ entrega (intacto)', function (): void {
    $delivery = sdlgLoadedDelivery();
    $outbox = sdlgOutbox();

    // Sin itinerario multi-tramo (ningún nodo es intermedio) y con el destino final del traspaso = el nodo
    // donde se deja: comportamiento histórico, se cierra como entregado (ShipmentDelivered, sin POD).
    $handler = new RecordStoreDropHandler(
        sdlgDeliveryRepo($delivery),
        sdlgRouteRepo(),
        sdlgFulfillment(),
        new RecordDomainEvents($outbox, sdlgClock()),
        sdlgUnits(['u-aaaa', 'u-bbbb']),
        sdlgTransfers(SDLG_FINAL),
        sdlgItinerary(null),
    );

    $handler->handle(new RecordStoreDrop(SDLG_SHIP, SDLG_FINAL, '2026-11-17T11:00:00+00:00'));

    expect($delivery->status())->toBe(DeliveryStatus::Delivered)
        ->and(array_map(static fn ($m): string => $m->eventName(), $outbox->messages))->toContain('execution.shipment_delivered')
        ->and(array_map(static fn ($m): string => $m->eventName(), $outbox->messages))->not->toContain('execution.shipment_leg_closed');
});
