<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\BindShipmentToTracking;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;

/**
 * Liga el envío al seguimiento del pedido, reconciliando el caso multishipping. Tolerante e idempotente:
 * nunca falla si el evento del outbox se reentrega.
 *
 * @implements CommandHandler<BindShipmentToTracking>
 */
final class BindShipmentToTrackingHandler implements CommandHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof BindShipmentToTracking) {
            throw TrackingException::because('BindShipmentToTrackingHandler solo maneja BindShipmentToTracking');
        }

        $all = $this->trackings->findAllByOrderReference($command->orderReference);

        // Ya ligado a este envío: nada que hacer (reentrega del outbox).
        foreach ($all as $tracking) {
            if ($tracking->shipmentId() === $command->shipmentId) {
                return;
            }
        }

        // Hay un seguimiento sin envío (el que se abrió al crear la orden): se le liga este envío.
        foreach ($all as $tracking) {
            if ($tracking->shipmentId() === null) {
                $tracking->bindShipment($command->shipmentId);
                $this->persist($tracking);

                return;
            }
        }

        // Todos los seguimientos del pedido ya están ligados: este es un envío HERMANO (multishipping).
        // Se abre un seguimiento propio, ligado y alineado al estado del pedido tras el pago (procesando).
        $sibling = ShipmentTracking::open(
            $this->trackings->nextIdentity(),
            $command->orderReference,
            TrackingToken::generate(),
            $command->remainingStops,
        );
        $sibling->bindShipment($command->shipmentId);
        $sibling->markProcessing();

        $this->persist($sibling);
    }

    private function persist(ShipmentTracking $tracking): void
    {
        $this->trackings->save($tracking);
        $this->recordEvents->record($tracking->releaseEvents(), 'tracking.shipment_tracking');
    }
}
