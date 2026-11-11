<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\MarkShipmentDeparted;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la SALIDA del camión con un envío (inicio de ruta). Idempotente: el agregado no reemite si la
 * entrega ya salió o está resuelta. Si no hay entrega abierta para el envío, no hace nada (tolerante: la
 * ruta puede incluir envíos sin entrega abierta todavía).
 *
 * @implements CommandHandler<MarkShipmentDeparted>
 */
final class MarkShipmentDepartedHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof MarkShipmentDeparted) {
            throw ExecutionException::because('MarkShipmentDepartedHandler solo maneja MarkShipmentDeparted');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            return;
        }

        $delivery->markDeparted(new DateTimeImmutable($command->occurredAt));

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
