<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\ConductPreTripChecklist;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\ChecklistTemplateRepository;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Fleet\Domain\Contract\VehicleFitnessLog;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Model\ChecklistTemplate;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Fleet\Domain\ValueObject\ChecklistEvaluation;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\PreTripChecklist;
use Logistics\Fleet\Domain\ValueObject\VehicleFitnessLogEntry;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Construye la inspección pre-salida con su checklist, la evalúa contra la plantilla del servidor, la
 * persiste y publica sus eventos al outbox.
 *
 * Si algún ítem CRÍTICO se reportó fallido, el vehículo queda NO APTO desde la fecha de la inspección
 * (`fleet.vehicle_marked_unfit`): ya no se le puede armar tripulación ni registrar salida hasta que un
 * supervisor lo habilite. Sin plantilla activa, el checklist no bloquea (comportamiento histórico). Si el
 * vehículo no está registrado en flota, se guarda la inspección sin marcar nada.
 *
 * El conjunto completo (inspección, vehículo, bitácora y outbox) corre en la misma transacción, que abre el decorador
 * transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ConductPreTripChecklist>
 */
final readonly class ConductPreTripChecklistHandler implements CommandHandler
{
    public function __construct(
        private PreTripInspectionRepository $inspections,
        private RecordDomainEvents $recordEvents,
        private ChecklistTemplateRepository $templates,
        private VehicleRepository $vehicles,
        private VehicleFitnessLog $fitnessLog,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ConductPreTripChecklist);

        $checklist = PreTripChecklist::of($command->items);
        $performedOn = new DateTimeImmutable($command->performedOn);

        // Hoy los vehículos no tienen tipo: aplica la plantilla por defecto de la flota.
        $template = $this->templates->activeFor(null);
        $evaluation = $template instanceof ChecklistTemplate
            ? $template->evaluate($checklist)
            : ChecklistEvaluation::withoutTemplate();

        $inspection = PreTripInspection::conduct(
            $this->inspections->nextIdentity(),
            VehicleId::fromString($command->vehicleId),
            DriverId::fromString($command->driverId),
            $checklist,
            $performedOn,
            $command->notes,
            $evaluation,
            $command->routeId,
        );

        $this->inspections->save($inspection);

        $this->recordEvents->record($inspection->releaseEvents(), 'fleet.pre_trip_inspection');

        if (!$evaluation->fitToDispatch && $template instanceof ChecklistTemplate) {
            $this->markVehicleUnfit($inspection, $template, $evaluation);
        }
    }

    /**
     * Deja el vehículo NO APTO, con un motivo legible armado con los textos de la plantilla.
     */
    private function markVehicleUnfit(
        PreTripInspection $inspection,
        ChecklistTemplate $template,
        ChecklistEvaluation $evaluation,
    ): void {
        $vehicle = $this->vehicles->findById($inspection->vehicleId());
        if ($vehicle === null || $vehicle->fitness()->isUnfit()) {
            return;
        }

        $labels = array_map(
            static fn (string $key): string => $template->labelFor($key),
            $evaluation->failedCritical,
        );
        $reason = 'Falla crítica en el checklist pre-salida: '.implode(', ', $labels);

        $vehicle->markUnfit(
            $inspection->performedOn(),
            $reason,
            $inspection->id()->toString(),
            $evaluation->failedCritical,
        );

        $this->vehicles->save($vehicle);

        $this->fitnessLog->append(
            new VehicleFitnessLogEntry(
                vehicleId: $vehicle->id()->toString(),
                action: VehicleFitnessLog::ACTION_MARKED_UNFIT,
                reason: $reason,
                failedItems: $evaluation->failedCritical,
                inspectionId: $inspection->id()->toString(),
                actorId: $inspection->driverId()->toString(),
                actorLabel: null,
            ),
            $this->clock->now(),
        );

        $this->recordEvents->record($vehicle->releaseEvents(), 'fleet.vehicle');
    }
}
