<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordCarrierHandoff;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentItineraryReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la ENTREGA A LA TRANSPORTADORA (handoff): carga la entrega del envío y le pide al agregado que
 * cierre el tramo de flota propia y declare el pase al tercero. La entrega NO pasa a Delivered.
 *
 * El tramo que se cierra y la transportadora (si el comando no la trae) salen del itinerario: el primer
 * tramo abierto es el que el envío venía recorriendo con flota propia. Sin lector de itinerario el seq
 * degrada a 0 y la transportadora queda null (el hecho igual se graba).
 *
 * Idempotente: el agregado descarta el escaneo si ya existe su idempotencyKey, y es un no-op sobre una
 * entrega ya cerrada.
 *
 * @implements CommandHandler<RecordCarrierHandoff>
 */
final class RecordCarrierHandoffHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly RecordDomainEvents $recordEvents,
        private readonly ?ShipmentItineraryReader $itinerary = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordCarrierHandoff) {
            throw ExecutionException::because('RecordCarrierHandoffHandler solo maneja RecordCarrierHandoff');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
        }

        $openLeg = $this->itinerary?->nextOpenLeg($command->shipmentId);
        $legSeq = $openLeg->seq ?? 0;
        $carrierId = $command->carrierId ?? $openLeg?->carrierId;

        $delivery->recordCarrierHandoff(
            $command->carrierHubNodeId,
            $carrierId,
            $legSeq,
            GeoPoint::fromNullable($command->lat, $command->lng),
            DeviceContext::of($command->deviceId, $command->userId),
            new DateTimeImmutable($command->occurredAt),
            $command->idempotencyKey,
        );

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
