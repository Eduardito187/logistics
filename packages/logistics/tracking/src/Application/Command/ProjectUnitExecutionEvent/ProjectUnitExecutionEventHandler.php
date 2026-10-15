<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent;

use DateTimeImmutable;
use Exception;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\UnitTracking;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;

/**
 * Traduce un evento de ejecución per-bulto al estado del bulto y lo aplica sobre su seguimiento.
 *
 * Tolerante por diseño (es una proyección): un tipo de evento que no mapea a un estado se ignora. El
 * read model per-bulto se AUTOCOMPLETA desde el flujo de eventos: la primera vez que se ve un bulto
 * (p. ej. su preparación) se abre su seguimiento; no hace falta un alta previa.
 *
 * Un desenlace del envío (entregado/fallido) llega sin unit_id y se aplica en cascada a todos los
 * bultos ya conocidos del envío. Idempotente y monótono: reenvíos del relay y reportes fuera de orden
 * no producen cambios espurios (ver UnitTracking::applyStatus).
 *
 * @implements CommandHandler<ProjectUnitExecutionEvent>
 */
final class ProjectUnitExecutionEventHandler implements CommandHandler
{
    public function __construct(
        private readonly UnitTrackingRepository $units,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ProjectUnitExecutionEvent) {
            throw TrackingException::because('ProjectUnitExecutionEventHandler solo maneja ProjectUnitExecutionEvent');
        }

        $status = UnitStatus::fromExecutionEvent($command->executionEventType);
        if ($status === null) {
            // El evento no afecta el estado del bulto: se ignora.
            return;
        }

        try {
            $seenAt = new DateTimeImmutable($command->occurredAt);
        } catch (Exception) {
            throw TrackingException::because("instante del evento inválido: {$command->occurredAt}");
        }

        $unitId = trim($command->unitId);

        // Desenlace del envío sin unit_id (entregado/fallido): cascada a todos los bultos conocidos.
        if ($unitId === '') {
            if ($status->isTerminal()) {
                $this->cascadeToShipment($command->shipmentId, $status, $seenAt);
            }

            return;
        }

        $this->projectUnit($command->shipmentId, $unitId, $status, $command->locationId, $seenAt);
    }

    /**
     * Proyecta el estado sobre un bulto concreto, abriendo su seguimiento la primera vez que se lo ve.
     */
    private function projectUnit(
        string $shipmentId,
        string $unitId,
        UnitStatus $status,
        ?string $locationId,
        DateTimeImmutable $seenAt,
    ): void {
        $unit = $this->units->findByUnitId($unitId);

        if ($unit === null) {
            $unit = UnitTracking::open($this->units->nextIdentity(), $shipmentId, $unitId);
        }

        $unit->applyStatus($status, $locationId, $seenAt);

        $this->units->save($unit);

        $this->recordEvents->record($unit->releaseEvents(), 'tracking.unit_tracking');
    }

    /**
     * Aplica el desenlace terminal del envío a todos sus bultos ya conocidos (no crea bultos nuevos:
     * si un bulto nunca se escaneó, no existe en el read model y no hay nada que cerrar).
     */
    private function cascadeToShipment(string $shipmentId, UnitStatus $status, DateTimeImmutable $seenAt): void
    {
        foreach ($this->units->listByShipmentId($shipmentId) as $unit) {
            $unit->applyStatus($status, null, $seenAt);
            $this->units->save($unit);
            $this->recordEvents->record($unit->releaseEvents(), 'tracking.unit_tracking');
        }
    }
}
