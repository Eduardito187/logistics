<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AssignShelfLocation;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la recepción del envío en el nodo y fija la ubicación en estante de cada producto pedido,
 * grabando NodeUnitShelved por cada cambio real (en la misma transacción, vía outbox).
 *
 * El lote es atómico: si un producto no pertenece a la recepción, no se ubica ninguno (422).
 *
 * @implements CommandHandler<AssignShelfLocation>
 */
final class AssignShelfLocationHandler implements CommandHandler
{
    public function __construct(
        private readonly NodeReceptionRepository $receptions,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AssignShelfLocation) {
            throw ExecutionException::because('AssignShelfLocationHandler solo maneja AssignShelfLocation');
        }

        if ($command->unitIds === []) {
            throw ExecutionException::because('la ubicación en estante requiere al menos un producto');
        }

        $reception = $this->receptions->findByShipmentAndDestination($command->shipmentId, $command->nodeId);
        if ($reception === null) {
            throw ExecutionException::because(
                "no hay recepción para el envío {$command->shipmentId} en el nodo {$command->nodeId}",
            );
        }

        $by = DeviceContext::of($command->deviceId, $command->userId);
        $at = new DateTimeImmutable($command->occurredAt);

        $changed = false;
        foreach (array_values(array_unique($command->unitIds)) as $unitId) {
            $changed = $reception->assignShelfLocation($unitId, $command->location, $by, $at) || $changed;
        }

        // Reenvío idéntico: nada cambió, no se escribe ni se emite nada.
        if (!$changed) {
            return;
        }

        $this->receptions->save($reception);

        $this->recordEvents->record($reception->releaseEvents(), 'execution.node_reception');
    }
}
