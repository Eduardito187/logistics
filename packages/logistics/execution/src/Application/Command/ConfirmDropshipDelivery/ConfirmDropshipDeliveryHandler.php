<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ConfirmDropshipDelivery;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Confirma la entrega de un envío dropship. La entrega ya debe existir (se abrió al confirmar el
 * despacho). El agregado valida que sea dropship por confirmación y la idempotencia.
 *
 * @implements CommandHandler<ConfirmDropshipDelivery>
 */
final class ConfirmDropshipDeliveryHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ConfirmDropshipDelivery) {
            throw ExecutionException::because('ConfirmDropshipDeliveryHandler solo maneja ConfirmDropshipDelivery');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            throw ExecutionException::because("no hay entrega dropship abierta para el envío {$command->shipmentId}");
        }

        $delivery->confirmSellerDelivery(
            new DateTimeImmutable($command->occurredAt),
            $command->idempotencyKey,
            $command->reference,
        );

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
