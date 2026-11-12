<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use DateTimeImmutable;
use Logistics\Planning\Domain\ValueObject\PlanDate;

/**
 * Puerta de persistencia del BACKLOG de replanificación: envíos que deben volver a planificarse para una
 * fecha (p. ej. porque el camión que los llevaba sufrió un incidente MAYOR y quedaron sin entregar).
 *
 * El dominio la define; la implementación Eloquent vive en Infrastructure. No es un agregado rico: es
 * una cola de trabajo pendiente que la planificación de esa fecha CONSUME: el proveedor de pendientes la
 * lee como candidatos y el auto-planificador la marca procesada cuando un plan generado los incluye.
 */
interface ReplanBacklogRepository
{
    /**
     * Encola (o reafirma) un envío para replanificar en la fecha dada. Idempotente por envío+fecha: si ya
     * estaba encolado para ese día, se actualiza el motivo/origen sin duplicar. Una reentrega del MISMO
     * hecho (mismo motivo y ruta de origen) sobre una fila ya procesada no la reabre; un hecho nuevo sí.
     */
    public function enqueue(string $shipmentId, PlanDate $date, string $reason, ?string $sourceRouteId): void;

    /**
     * Ids de los envíos encolados para la fecha que todavía NO fueron consumidos por un plan.
     *
     * @return list<string>
     */
    public function pendingShipmentIdsFor(PlanDate $date): array;

    /**
     * Marca como procesadas (consumidas) las filas pendientes de la fecha para los envíos dados.
     * Idempotente: una fila ya procesada no se vuelve a tocar; ids que no están en el backlog se ignoran.
     *
     * @param list<string> $shipmentIds
     */
    public function markProcessed(array $shipmentIds, PlanDate $date, DateTimeImmutable $at): void;
}
