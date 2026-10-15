<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordNodeReception;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la entrega del envío y registra la recepción en nodo.
 *
 * Idempotente: el agregado descarta el escaneo si ya existe su idempotencyKey.
 *
 * @implements CommandHandler<RecordNodeReception>
 */
final class RecordNodeReceptionHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordNodeReception) {
            throw ExecutionException::because('RecordNodeReceptionHandler solo maneja RecordNodeReception');
        }

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
        }

        $delivery->recordNodeReception(
            $command->nodeId,
            $command->unitId,
            GeoPoint::of($command->lat, $command->lng),
            DeviceContext::of($command->deviceId, $command->userId),
            new DateTimeImmutable($command->occurredAt),
            $command->idempotencyKey,
        );

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');
    }
}
