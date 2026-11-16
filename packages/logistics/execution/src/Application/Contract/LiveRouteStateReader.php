<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Puerta de lectura CROSS-CONTEXT del estado EN VIVO de la ruta de un vehículo, que calcula el MOTOR ÚNICO
 * de ETA (telemetría, a partir de la última posición del camión: el mismo cálculo que alimenta
 * `/app/vehicles/{id}/etas` y la ventana del cliente). Ejecución no duplica la fórmula: solo consume este
 * puerto y superpone la ETA viva y el estado "fuera de ruta" sobre su hoja de ruta.
 *
 * Tolerante: si el motor de ETA no está disponible (contexto telemetría ausente, docker nuevo) devuelve
 * null y la hoja de ruta cae a la ETA planificada que ya trae cada parada.
 *
 * `reroute_geometry` es la polilínea (lista de `{lat, lng}`) desde la posición actual del camión hasta su
 * próxima parada pendiente, que calcula el mismo motor y que la app usa para REDIBUJAR la ruta cuando el camión
 * está fuera de ruta. Null cuando no está fuera de ruta (o el motor no la entregó).
 *
 * @phpstan-type LiveRouteState array{off_route: bool, off_route_since: string|null, position: array{lat: float, lng: float, recorded_at: string}|null, position_stale: bool, etas: array<string, string>, reroute_geometry: list<array{lat: float, lng: float}>|null}
 */
interface LiveRouteStateReader
{
    /**
     * Estado en vivo del vehículo, o null si no se pudo obtener del motor de ETA.
     *
     * @return LiveRouteState|null
     */
    public function forVehicle(string $vehicleId): ?array;
}
