<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\ProjectExecutionEvent;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Traduce un evento de ejecución al estado visible y lo aplica sobre el seguimiento del envío.
 *
 * Tolerante por diseño (es una proyección): si el tipo de evento no mapea a un estado visible, o no
 * existe seguimiento para ese envío, no hace nada en vez de fallar. Así el relay puede reenviar sin
 * que un evento irrelevante tumbe la proyección.
 *
 * @implements CommandHandler<ProjectExecutionEvent>
 */
final class ProjectExecutionEventHandler implements CommandHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ProjectExecutionEvent) {
            throw TrackingException::because('ProjectExecutionEventHandler solo maneja ProjectExecutionEvent');
        }

        $status = VisibleStatus::fromExecutionEvent($command->executionEventType);
        if ($status === null) {
            // El evento no afecta el estado visible: se ignora.
            return;
        }

        $tracking = $this->trackings->findByShipmentId($command->shipmentId);
        if ($tracking === null) {
            // Todavía no se abrió el seguimiento de este envío: nada que proyectar.
            return;
        }

        // Antes de aplicar: ¿ya estaba cerrado? Si ya era terminal, este evento es un reenvío del relay
        // y no debe volver a avanzar la cascada (evita el doble descuento en reintentos).
        $wasTerminal = $tracking->status()->isTerminal();

        $tracking->applyStatus($status);

        $this->trackings->save($tracking);

        $this->recordEvents->record($tracking->releaseEvents(), 'tracking.shipment_tracking');

        // Una parada se completó (entrega resuelta: entregada o fallida) POR PRIMERA VEZ. Si el envío
        // tiene ubicación en la ruta, los envíos de atrás en la MISMA ruta quedan una parada más cerca
        // del cliente: se baja su stops_remaining en cascada. Es un ajuste de proyección (no graba
        // eventos de negocio) y corre una sola vez por parada, no en cada reintento del relay.
        if (!$wasTerminal && $status->isTerminal()) {
            $this->advanceLaterStops($tracking->routeId(), $tracking->routePosition()?->value);
        }
    }

    /**
     * Baja en uno las paradas restantes de cada envío de la misma ruta con posición posterior a la que
     * acaba de completarse. Sin ruta/posición conocida no hay cascada.
     */
    private function advanceLaterStops(?string $routeId, ?int $position): void
    {
        if ($routeId === null || $position === null) {
            return;
        }

        foreach ($this->trackings->stopsAheadOnRoute($routeId, $position) as $ahead) {
            $ahead->decrementStops();
            $this->trackings->save($ahead);
        }
    }
}
