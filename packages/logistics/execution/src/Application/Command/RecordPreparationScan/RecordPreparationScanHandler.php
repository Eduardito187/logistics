<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordPreparationScan;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Abre la entrega del envío si hace falta y registra el escaneo de preparación.
 *
 * Al abrir una entrega nueva lee el `fulfillment_mode` del envío solo para REGISTRARLO (reportes y
 * liquidaciones): cada pedido —OWNED, FBD o DROPSHIP— se despacha con el mismo flujo de escaneos propios.
 *
 * Idempotente: el agregado descarta el escaneo si ya existe su idempotencyKey; en ese caso no se
 * graba ningún evento nuevo.
 *
 * @implements CommandHandler<RecordPreparationScan>
 */
final class RecordPreparationScanHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly ShipmentFulfillmentReader $fulfillment,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordPreparationScan) {
            throw ExecutionException::because('RecordPreparationScanHandler solo maneja RecordPreparationScan');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId)
            ?? $this->startFor($command);

        $delivery->recordPreparationScan(
            $command->unitId,
            GeoPoint::of($command->lat, $command->lng),
            DeviceContext::of($command->deviceId, $command->userId),
            new DateTimeImmutable($command->occurredAt),
            $command->idempotencyKey,
        );

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }

    /**
     * Abre la entrega registrando el modo de fulfillment del envío (dato, no cambia el flujo).
     */
    private function startFor(RecordPreparationScan $command): Delivery
    {
        return Delivery::start(
            $this->deliveries->nextIdentity(),
            $command->shipmentId,
            $command->vehicleId,
            $this->fulfillment->forShipmentId($command->shipmentId)->mode,
        );
    }
}
