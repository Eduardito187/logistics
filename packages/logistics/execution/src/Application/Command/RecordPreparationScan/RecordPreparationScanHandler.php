<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordPreparationScan;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\DropshipHandling;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;

/**
 * Abre la entrega del envío si hace falta y registra el escaneo de preparación.
 *
 * Al abrir una entrega nueva lee el `fulfillment_mode` del envío para BIFURCAR: OWNED/FBD abren el flujo
 * propio normal. Que un escaneo de preparación propio llegue para un envío DROPSHIP implica que Dismac
 * hace la última milla, así que se abre como dropship-LastMile (escaneos propios habilitados). Un dropship
 * de solo-tracking/tercero no debe pasar por acá: su entrega se abre vía ConfirmDropshipDispatch y el
 * agregado rechaza los escaneos propios.
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
     * Abre la entrega con el modo de fulfillment del envío. Dropship con escaneo propio ⇒ última milla de
     * Dismac (LastMile); OWNED/FBD ⇒ sin modo de manejo.
     */
    private function startFor(RecordPreparationScan $command): Delivery
    {
        $mode = $this->fulfillment->forShipmentId($command->shipmentId)->mode;
        $handling = $mode === FulfillmentMode::Dropship ? DropshipHandling::LastMile : null;

        return Delivery::start(
            $this->deliveries->nextIdentity(),
            $command->shipmentId,
            $command->vehicleId,
            $mode,
            $handling,
        );
    }
}
