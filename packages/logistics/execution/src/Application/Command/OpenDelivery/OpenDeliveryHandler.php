<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\OpenDelivery;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Asegura la entrega del envío al asignar el camión de reparto:
 *  - si NO existe, la abre con ese vehículo;
 *  - si YA existe y sigue en curso (no entregada ni fallida), la MUEVE al camión nuevo (el pedido pasa al
 *    último camión asignado); una entrega REAL ya cerrada (DELIVERED/FAILED) no se mueve (se respeta el POD).
 * Idempotente: reasignar al mismo camión no hace nada.
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

        $existing = $this->deliveries->findByShipmentId($command->shipmentId);

        if ($existing !== null) {
            // Ya hay entrega: mover al camión nuevo si sigue en curso (el dominio respeta DELIVERED/FAILED).
            if ($existing->reassignVehicle($command->vehicleId, new DateTimeImmutable)) {
                $this->deliveries->save($existing);
                $this->recordEvents->record($existing->releaseEvents(), 'execution.delivery');
            }

            return;
        }

        $delivery = Delivery::open(
            $this->deliveries->nextIdentity(),
            $command->shipmentId,
            $command->vehicleId,
            $this->fulfillment->forShipmentId($command->shipmentId)->mode,
            new DateTimeImmutable,
        );

        $this->deliveries->save($delivery);
        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
