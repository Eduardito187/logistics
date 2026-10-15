<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\ConductPreTripChecklist;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\PreTripChecklist;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye la inspección pre-salida con su checklist, la persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ConductPreTripChecklist>
 */
final readonly class ConductPreTripChecklistHandler implements CommandHandler
{
    public function __construct(
        private PreTripInspectionRepository $inspections,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ConductPreTripChecklist);

        $inspection = PreTripInspection::conduct(
            $this->inspections->nextIdentity(),
            VehicleId::fromString($command->vehicleId),
            DriverId::fromString($command->driverId),
            PreTripChecklist::of($command->items),
            new DateTimeImmutable($command->performedOn),
            $command->notes,
        );

        $this->inspections->save($inspection);

        $this->recordEvents->record($inspection->releaseEvents(), 'fleet.pre_trip_inspection');
    }
}
