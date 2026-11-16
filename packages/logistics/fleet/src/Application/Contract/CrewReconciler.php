<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Contract;

/**
 * Saneo de tripulaciones duplicadas que dejaron datos viejos: por cada chofer + fecha (y luego por cada
 * vehículo + fecha) con más de una tripulación vigente, deja la MÁS RECIENTE y pasa las demás al historial.
 *
 * Idempotente: sin duplicados no hace nada. No reemite eventos al outbox (son datos ya ocurridos; volver a
 * publicarlos duplicaría lo que reaccionó en su momento).
 *
 * @phpstan-type ReconcileReport array{driver_groups: int, driver_crews: int, vehicle_groups: int, vehicle_crews: int, indexes_ready: bool}
 */
interface CrewReconciler
{
    /**
     * Con `$apply` en false solo cuenta (simulación). `*_groups` = pares con duplicados; `*_crews` =
     * tripulaciones que quedan (o quedarían) sin efecto. `indexes_ready` = los índices únicos existen.
     *
     * @return ReconcileReport
     */
    public function reconcile(bool $apply, int $chunk): array;
}
