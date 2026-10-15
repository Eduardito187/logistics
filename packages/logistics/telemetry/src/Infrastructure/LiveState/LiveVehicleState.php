<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Estado CALIENTE (hot) de la flota en Valkey: la ÚLTIMA posición conocida de cada vehículo, en forma
 * PRIMITIVA (array), con TTL. Es la fuente que un espectador recién conectado consulta para pintar la
 * flota al instante, sin escanear la tabla de pings ni esperar al siguiente broadcast.
 *
 * Valkey habla el protocolo redis: se usa el store `redis` del cache (configurable por .env). Toda
 * operación DEGRADA CON GRACIA (try/catch): si Valkey no está disponible, el hot-state se comporta como
 * vacío y la ingesta/consulta sigue funcionando contra la base. Nunca rompe la ingesta.
 *
 * Además lleva el THROTTLE/coalescing del fan-out: `shouldEmit()` deja pasar ~1 difusión por vehículo
 * cada N segundos usando `add()` (set-if-absent atómico con TTL), para no inundar a los espectadores con
 * cada ping. Si Valkey no responde, se opta por emitir (fail-open): mejor difundir de más que silenciar.
 *
 * Se cachea la FORMA PRIMITIVA (array), nunca un objeto de dominio serializado (regla 7 de CLAUDE.md).
 */
final class LiveVehicleState
{
    /** Prefijo de las claves de última posición por vehículo. */
    private const POSITION_KEY = 'telemetry:live:pos:';

    /** Prefijo de las claves de throttle de difusión por vehículo. */
    private const EMIT_KEY = 'telemetry:live:emit:';

    /**
     * Guarda la última posición del vehículo (forma primitiva) con TTL. No lanza si Valkey falla.
     *
     * @param array{vehicle_id: string, plate: string|null, lat: float, lng: float, status: string, occurred_at: string} $position
     */
    public function remember(string $vehicleId, array $position): void
    {
        try {
            $this->store()->put(self::POSITION_KEY.$vehicleId, $position, $this->ttlSeconds());
        } catch (Throwable) {
            // Degradación con gracia: el hot-state es un acelerador, no la fuente de verdad.
        }
    }

    /**
     * Última posición conocida del vehículo, o null si no hay dato caliente (o Valkey no responde).
     *
     * @return array{vehicle_id: string, plate: string|null, lat: float, lng: float, status: string, occurred_at: string}|null
     */
    public function get(string $vehicleId): ?array
    {
        try {
            /** @var array{vehicle_id: string, plate: string|null, lat: float, lng: float, status: string, occurred_at: string}|null $value */
            $value = $this->store()->get(self::POSITION_KEY.$vehicleId);

            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * ¿Toca difundir ahora para este vehículo? Deja pasar como mucho una difusión por ventana de throttle.
     *
     * Implementación atómica con `add()` (NX + EXPIRE): la primera llamada de la ventana graba la marca y
     * devuelve true; las siguientes devuelven false hasta que la marca expira. Si Valkey no responde se
     * emite igual (fail-open), para no quedarse sin tiempo real por un fallo del acelerador.
     */
    public function shouldEmit(string $vehicleId): bool
    {
        try {
            return $this->store()->add(self::EMIT_KEY.$vehicleId, 1, $this->throttleSeconds());
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Store de cache respaldado por Valkey (redis). Configurable por si el proyecto nombra otro store.
     */
    private function store(): CacheRepository
    {
        /** @var string $store */
        $store = config('logistics-telemetry.live.cache_store', 'redis');

        return Cache::store($store);
    }

    private function ttlSeconds(): int
    {
        return (int) config('logistics-telemetry.live.ttl_seconds', 300);
    }

    private function throttleSeconds(): int
    {
        return (int) config('logistics-telemetry.live.throttle_seconds', 3);
    }
}
