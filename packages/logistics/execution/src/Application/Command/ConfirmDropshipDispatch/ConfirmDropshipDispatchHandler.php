<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ConfirmDropshipDispatch;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DropshipHandling;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;

/**
 * Confirma el despacho de un envío dropship. BIFURCA por `fulfillment_mode` del envío: si no es dropship
 * rechaza (los OWNED/FBD se despachan por el flujo propio de escaneos). Abre la entrega en modo dropship
 * si no existía y delega la transición + idempotencia al agregado.
 *
 * @implements CommandHandler<ConfirmDropshipDispatch>
 */
final class ConfirmDropshipDispatchHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly ShipmentFulfillmentReader $fulfillment,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ConfirmDropshipDispatch) {
            throw ExecutionException::because('ConfirmDropshipDispatchHandler solo maneja ConfirmDropshipDispatch');
        }

        $ownership = $this->fulfillment->forShipmentId($command->shipmentId);
        if ($ownership->mode !== FulfillmentMode::Dropship) {
            throw ExecutionException::because(
                "el envío {$command->shipmentId} no es dropship ({$ownership->mode->value}); se despacha por el flujo propio",
            );
        }

        $handling = DropshipHandling::from($command->handling);

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId)
            ?? Delivery::start(
                $this->deliveries->nextIdentity(),
                $command->shipmentId,
                $command->carrierTransportId,
                FulfillmentMode::Dropship,
                $handling,
            );

        $delivery->confirmSellerDispatch(
            new DateTimeImmutable($command->occurredAt),
            $command->idempotencyKey,
            $command->reference,
        );

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
