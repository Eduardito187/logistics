<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\ProjectExecutionEvent;

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

        $tracking->applyStatus($status);

        $this->trackings->save($tracking);

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $tracking->releaseEvents();
    }
}
