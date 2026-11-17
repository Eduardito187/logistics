<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Contract;

/**
 * Caché (forma PRIMITIVA) de la geometría del reruteo por vehículo: la polilínea desde la posición actual del
 * camión hasta su próxima parada pendiente, que el comando programado calcula con OSRM y la lectura reutiliza
 * para no pegarle a OSRM en cada request. TTL corto; degrada con gracia si el store no responde.
 *
 * Se guarda la lista de puntos `{lat, lng}` (nunca un objeto de dominio serializado, regla 7 de CLAUDE.md).
 */
interface RerouteGeometryCache
{
    /**
     * Geometría cacheada del vehículo, o null si no hay (o el store no respondió).
     *
     * @return list<array{lat: float, lng: float}>|null
     */
    public function get(string $vehicleId): ?array;

    /**
     * Guarda la geometría del vehículo con el TTL corto configurado. No lanza si el store falla.
     *
     * @param list<array{lat: float, lng: float}> $geometry
     */
    public function put(string $vehicleId, array $geometry): void;

    /** Descarta la geometría cacheada del vehículo (ya no está fuera de ruta). No lanza si el store falla. */
    public function forget(string $vehicleId): void;
}
