<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReconcileNodeReception;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\MissingUnitDeclaration;
use Logistics\Execution\Domain\ValueObject\MissingUnitReason;
use Logistics\Execution\Domain\ValueObject\ReconciliationReport;
use Logistics\Execution\Domain\ValueObject\SurplusUnitDeclaration;
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
            $this->reportFrom($command),
        );

        $this->receptions->save($reception);

        $this->recordEvents->record($reception->releaseEvents(), 'execution.node_reception');
    }

    /**
     * Arma el informe del operador desde los primitivos del comando. Un motivo desconocido se rechaza
     * (la validación HTTP ya lo filtra; esto protege a otros emisores del comando).
     */
    private function reportFrom(ReconcileNodeReception $command): ReconciliationReport
    {
        $missing = [];
        foreach ($command->missing as $row) {
            $reason = MissingUnitReason::tryFrom($row['reason'])
                ?? throw ExecutionException::because("motivo de faltante desconocido: {$row['reason']}");
            $missing[] = MissingUnitDeclaration::of($row['unit_id'], $reason);
        }

        $surplus = [];
        foreach ($command->surplus as $row) {
            $surplus[] = SurplusUnitDeclaration::of($row['unit_id'], $row['belongs_to_shipment_id']);
        }

        return ReconciliationReport::of($command->notes, $command->photoPath, $missing, $surplus);
    }
}
