<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent;

use Logistics\Shared\Application\Command\Command;

/**
 * Proyecta un evento de ejecución PER-BULTO sobre el read model per-bulto del seguimiento.
 *
 * Es el puente que, al integrar, se suscribe a los eventos de execution que llevan unit_id (preparado,
 * cargado, descargado, recepción en nodo). El relay traduce cada evento a su tipo (string namespaced),
 * el id del envío, el del bulto, la ubicación (nodeId) si aplica y el instante del reporte en ISO 8601.
 *
 * Los desenlaces del envío (entregado/fallido) no traen unit_id: se despachan con unitId vacío y el
 * handler los aplica EN CASCADA a todos los bultos ya conocidos del envío.
 */
final readonly class ProjectUnitExecutionEvent implements Command
{
    public function __construct(
        public string $executionEventType,
        public string $shipmentId,
        public string $unitId,
        public string $occurredAt,
        public ?string $locationId = null,
    ) {}
}
