<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Saneo de las paradas que quedaron de más en las rutas por datos viejos: antes la asignación de un pedido a
 * un camión caía en «cualquier ruta abierta» (incluso una de otro día sin cerrar), así que una ruta
 * acumulaba paradas de varias jornadas y de pedidos ya entregados. Este saneo deja en cada ruta NO cerrada
 * solo las paradas que le corresponden (plan publicado de ese camión/día + las sumadas ese día), quitando
 * las arrastradas y las de envíos cerrados antes del día de la ruta.
 *
 * Conservador: nunca toca una ruta cerrada ni una parada con actividad registrada ese mismo día. No reemite
 * eventos al outbox (son hechos ya ocurridos; volver a publicarlos duplicaría lo que reaccionó en su momento).
 *
 * @phpstan-type ReconcileReport array{routes_scanned: int, routes_touched: int, stops_removed: int}
 */
interface RouteStopsReconciler
{
    /**
     * Con `$apply` en false solo cuenta (simulación). `routes_scanned` = rutas no cerradas revisadas;
     * `routes_touched` = rutas con paradas de más; `stops_removed` = paradas que se quitaron (o se quitarían).
     *
     * @return ReconcileReport
     */
    public function reconcile(bool $apply, int $chunk): array;
}
