<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

/**
 * Lado lectura cross-context: las paradas de la ruta REAL del camión (la que ejecuta el chofer), para que
 * el seguimiento sepa en qué posición va cada envío y cuántas paradas pendientes tiene por delante. Solo
 * primitivos: el seguimiento no se acopla al agregado de execution.
 *
 * @phpstan-type RouteStop array{shipment_id: string, sequence: int, pending: bool}
 */
interface RouteStopsReader
{
    /**
     * Paradas de la ruta en orden de visita. `pending` es false cuando la parada ya se resolvió (entregada,
     * fallida o reprogramada). Lista vacía si la ruta no existe.
     *
     * @return list<RouteStop>
     */
    public function stopsOfRoute(string $routeId): array;

    /**
     * Ids de las rutas que todavía tienen alguna parada pendiente (para la reconciliación).
     *
     * @return list<string>
     */
    public function routesWithPendingStops(): array;

    /**
     * Ids de los envíos que no tienen seguimiento abierto (para la reconciliación), en lotes.
     *
     * @return list<string>
     */
    public function shipmentsWithoutTracking(int $limit): array;
}
