<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\ProjectExecutionEvent;

use Logistics\Shared\Application\Command\Command;

/**
 * Proyecta un evento de ejecución ya normalizado sobre el read model del seguimiento.
 *
 * Es el PUENTE que, al integrar los contextos, se suscribirá a los eventos de execution: el relay
 * traduce cada evento a su tipo (string namespaced, p. ej. "execution.shipment_delivered") y el id del
 * envío, y lo manda por este comando. Acá no llega el objeto de dominio de otro contexto, solo datos.
 *
 * `occurredAt` (ISO 8601, opcional) es el momento del hecho de ejecución; al proyectar la entrega se usa
 * como `delivered_at`. Sin valor (o inválido) el handler usa el momento actual.
 */
final readonly class ProjectExecutionEvent implements Command
{
    public function __construct(
        public string $executionEventType,
        public string $shipmentId,
        public ?string $occurredAt = null,
    ) {}
}
