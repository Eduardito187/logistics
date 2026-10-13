<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReconcileNodeReception;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la recepción del envío en el nodo y la concilia, grabando NodeReceptionReconciled con las
 * discrepancias (faltantes/sobrantes). Ese evento alimenta tracking y el conector SAP por el outbox.
 *
 * Idempotente: una segunda conciliación no re-emite el evento (el agregado no hace nada si ya estaba
 * conciliada).
 *
 * @implements CommandHandler<ReconcileNodeReception>
 */
final class ReconcileNodeReceptionHandler implements CommandHandler
{
    public function __construct(
        private readonly NodeReceptionRepository $receptions,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReconcileNodeReception) {
            throw ExecutionException::because('ReconcileNodeReceptionHandler solo maneja ReconcileNodeReception');
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

        $reception->reconcile(
            DeviceContext::of($command->deviceId, $command->userId),
            new DateTimeImmutable($command->occurredAt),
        );

        $this->receptions->save($reception);

        $this->recordEvents->record($reception->releaseEvents(), 'execution.node_reception');
    }
}
