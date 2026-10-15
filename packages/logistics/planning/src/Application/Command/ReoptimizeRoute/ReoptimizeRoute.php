<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ReoptimizeRoute;

use Logistics\Shared\Application\Command\Command;

/**
 * RE-OPTIMIZA la ruta de UN plano afectado (R-05) sin rehacer el día: re-secuencia sus paradas con
 * 2-opt/Or-opt sobre la matriz real por carretera, recalcula ETA y geometría, y deja el plan igual o mejor
 * (nunca peor) que su orden actual. Es la reacción barata ante un cambio puntual (nuevo pedido ya asignado
 * al plan, cancelación de una parada, incidente que alteró el recorrido).
 *
 * Idempotente: re-secuenciar sobre una ruta ya óptima no la cambia (punto fijo del descenso). Respeta la
 * regla geográfica de forma implícita (no mueve paradas entre departamentos; solo reordena las del mismo
 * plan) y R-04 (no cambia la asignación camión↔envíos, solo el orden).
 *
 * Las coordenadas y franjas de las paradas las aporta quien conoce los envíos. `departAt` (ISO-8601) fija
 * el reloj de las ETA. `reason` describe el disparador (new_order | cancellation | incident | manual).
 */
final readonly class ReoptimizeRoute implements Command
{
    /**
     * @param list<ReoptimizeStopInput> $stops coordenadas/franjas de las paradas actuales del plan
     */
    public function __construct(
        public string $planId,
        public float $depotLatitude,
        public float $depotLongitude,
        public array $stops,
        public string $departAt,
        public string $reason = 'manual',
    ) {}
}
