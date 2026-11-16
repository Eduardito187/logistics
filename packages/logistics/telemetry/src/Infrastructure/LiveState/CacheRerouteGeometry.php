<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Logistics\Telemetry\Application\Contract\RerouteGeometryCache;
use Throwable;

/**
 * Caché de la geometría del reruteo respaldada por Valkey (store `redis`, el mismo del estado caliente de la
 * flota). Guarda la FORMA PRIMITIVA (lista de puntos `{lat, lng}`) con TTL corto, así que es segura con
 * workers persistentes (Octane): no memoiza nada en el proceso, el estado vive en el store de caché.
 *
 * Toda operación DEGRADA CON GRACIA (try/catch): sin Valkey la caché se comporta como vacía y la lectura cae
 * al respaldo en línea recta. Es un acelerador para no pegarle a OSRM en cada request, no la fuente de verdad.
 */
final class CacheRerouteGeometry implements RerouteGeometryCache
{
    /** Prefijo de las claves de geometría de reruteo por vehículo. */
    private const KEY = 'telemetry:reroute-geometry:';

    public function get(string $vehicleId): ?array
    {
        try {
            return $this->sanitize($this->store()->get(self::KEY.$vehicleId));
        } catch (Throwable) {
            return null;
        }
    }

    public function put(string $vehicleId, array $geometry): void
    {
        try {
            $this->store()->put(self::KEY.$vehicleId, $geometry, $this->ttlSeconds());
        } catch (Throwable) {
            // Degradación con gracia: la caché es un acelerador; vence sola con su TTL.
        }
    }

    public function forget(string $vehicleId): void
    {
        try {
            $this->store()->forget(self::KEY.$vehicleId);
        } catch (Throwable) {
            // Lo que no se pudo borrar vence solo con su TTL.
        }
    }

    /**
     * Valida el valor cacheado a la forma del puerto (lista de puntos `{lat, lng}` numéricos) o null: un valor
     * corrupto o incompleto no se devuelve como si fuera una geometría válida.
     *
     * @return list<array{lat: float, lng: float}>|null
     */
    private function sanitize(mixed $value): ?array
    {
        if (!is_array($value) || count($value) < 2) {
            return null;
        }

        $points = [];
        foreach ($value as $point) {
            if (!is_array($point) || !is_numeric($point['lat'] ?? null) || !is_numeric($point['lng'] ?? null)) {
                return null;
            }
            $points[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
        }

        return $points;
    }

    /** Store de cache respaldado por Valkey (redis); el mismo que el estado caliente de la flota. */
    private function store(): CacheRepository
    {
        /** @var string $store */
        $store = config('logistics-telemetry.live.cache_store', 'redis');

        return Cache::store($store);
    }

    /** TTL corto (segundos) de la geometría cacheada: mayor que el minuto del scheduler para quedar caliente. */
    private function ttlSeconds(): int
    {
        return max(1, (int) config('logistics-telemetry.eta.reroute_geometry_ttl_seconds', 90));
    }
}
