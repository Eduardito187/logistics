<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\PlanDate;

/**
 * Puerta de persistencia del BACKLOG de replanificación: envíos que deben volver a planificarse para una
 * fecha (p. ej. porque el camión que los llevaba sufrió un incidente MAYOR y quedaron sin entregar).
 *
 * El dominio la define; la implementación Eloquent vive en Infrastructure. No es un agregado rico: es
 * una cola de trabajo pendiente que la planificación del día siguiente consume para rearmar los planes.
 */
interface ReplanBacklogRepository
{
    /**
     * Encola (o reafirma) un envío para replanificar en la fecha dada. Idempotente por envío+fecha: si ya
     * estaba encolado para ese día, se actualiza el motivo/origen sin duplicar.
     */
    public function enqueue(string $shipmentId, PlanDate $date, string $reason, ?string $sourceRouteId): void;
}
