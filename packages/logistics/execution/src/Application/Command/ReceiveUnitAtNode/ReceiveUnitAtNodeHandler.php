<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReceiveUnitAtNode;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la recepción abierta del envío en el nodo y registra el escaneo de un bulto recibido.
 *
 * Idempotente: el agregado descarta el escaneo si ya existe su idempotencyKey.
 *
 * @implements CommandHandler<ReceiveUnitAtNode>
 */
final class ReceiveUnitAtNodeHandler implements CommandHandler
{
    public function __construct(
        private readonly NodeReceptionRepository $receptions,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReceiveUnitAtNode) {
            throw ExecutionException::because('ReceiveUnitAtNodeHandler solo maneja ReceiveUnitAtNode');
        }

        $reception = $this->receptions->findByShipmentAndDestination(
            $command->shipmentId,
            $command->destinationNodeId,
        );
        if ($reception === null) {
            throw ExecutionException::because(
                "no hay recepción abierta para el envío {$command->shipmentId} en el nodo {$command->destinationNodeId}",
            );
        }

        $reception->receiveUnit(
            $command->unitId,
            GeoPoint::of($command->lat, $command->lng),
            DeviceContext::of($command->deviceId, $command->userId),
            new DateTimeImmutable($command->occurredAt),
            $command->idempotencyKey,
        );

        $this->receptions->save($reception);

        $this->recordEvents->record($reception->releaseEvents(), 'execution.node_reception');
    }
}
