<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\OpenDelivery;

use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Abre la entrega del envío si todavía no existe. Idempotente: si ya hay entrega para el envío, no hace
 * nada (no duplica ni reabre), así reasignar el vehículo del pedido es seguro.
 *
 * Registra el `fulfillment_mode` del envío solo como dato (reportes/liquidaciones): OWNED, FBD y DROPSHIP
 * se despachan con el mismo flujo. Mismo criterio que RecordPreparationScanHandler::startFor.
 *
 * @implements CommandHandler<OpenDelivery>
 */
final class OpenDeliveryHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly ShipmentFulfillmentReader $fulfillment,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof OpenDelivery) {
            throw ExecutionException::because('OpenDeliveryHandler solo maneja OpenDelivery');
        }

        // Idempotente: una entrega ya abierta (o en cualquier estado) no se toca.
        if ($this->deliveries->findByShipmentId($command->shipmentId) !== null) {
            return;
        }

        $delivery = Delivery::start(
            $this->deliveries->nextIdentity(),
            $command->shipmentId,
            $command->vehicleId,
            $this->fulfillment->forShipmentId($command->shipmentId)->mode,
        );

        $this->deliveries->save($delivery);
        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
