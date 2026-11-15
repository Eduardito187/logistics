<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Logistics\Telemetry\Application\Contract\LivePositionHotState;
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
 *
 * También guarda lo necesario para la EMISIÓN FINAL de `fleet.position` tras el throttle: la última lectura
 * descartada (pendiente), el instante de la última difundida y la reserva del trabajo que la emite.
 *
 * @phpstan-import-type FleetSnapshot from LiveFleetViewFactory
 */
final class LiveVehicleState implements LivePositionHotState
{
    /** Prefijo de las claves de última posición por vehículo. */
    private const POSITION_KEY = 'telemetry:live:pos:';

    /** Prefijo de las claves de throttle de difusión por vehículo. */
    private const EMIT_KEY = 'telemetry:live:emit:';

    /** Prefijo de las claves de throttle del evento `fleet.position` (app) por vehículo. */
    private const FLEET_EMIT_KEY = 'telemetry:live:emit-fleet:';

    /** Prefijo de la última lectura que el throttle de `fleet.position` descartó (pendiente de emitir). */
    private const FLEET_PENDING_KEY = 'telemetry:live:pending-fleet:';

    /** Prefijo del instante de la lectura del último `fleet.position` difundido por vehículo. */
    private const FLEET_LAST_KEY = 'telemetry:live:last-fleet:';

    /** Prefijo de la reserva del trabajo de emisión final (a lo sumo uno en espera por vehículo). */
    private const FLEET_TRAILING_KEY = 'telemetry:live:trailing-fleet:';

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
     * Adelanta la última posición en el momento de la ingesta (modo `buffered`), sin tocar la base: conserva
     * la placa ya conocida del estado caliente y solo avanza si la lectura es más reciente que la guardada
     * (un lote atrasado no retrocede el dato). El estado (en marcha / ralentí / detenido) se deriva igual
     * que en el fan-out. No lanza: `get` y `remember` ya degradan con gracia.
     */
    public function touch(
        string $vehicleId,
        float $lat,
        float $lng,
        ?float $speedKph,
        ?bool $ignition,
        DateTimeImmutable $occurredAt,
    ): void {
        $current = $this->get($vehicleId);

        if ($current !== null) {
            $known = strtotime($current['occurred_at']);
            if ($known !== false && $known >= $occurredAt->getTimestamp()) {
                return;
            }
        }

        $threshold = (float) config('logistics-telemetry.movement.moving_threshold_kph', 3.0);
        $status = 'stopped';
        if ($speedKph !== null && $speedKph >= $threshold) {
            $status = 'moving';
        } elseif ($ignition === true) {
            $status = 'idle';
        }

        $this->remember($vehicleId, [
            'vehicle_id' => $vehicleId,
            'plate' => $current['plate'] ?? null,
            'lat' => $lat,
            'lng' => $lng,
            'status' => $status,
            'occurred_at' => $occurredAt->format(DATE_ATOM),
        ]);
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
     * ¿Toca difundir ahora el evento `fleet.position` (app) para este vehículo? Mismo mecanismo atómico que
     * `shouldEmit()`, con su propia marca y su propia ventana (`live.fleet_throttle_seconds`, 5 s por
     * defecto): el ritmo de la app se afina sin tocar el del admin. Si Valkey no responde, se emite.
     */
    public function shouldEmitFleet(string $vehicleId): bool
    {
        try {
            return $this->store()->add(self::FLEET_EMIT_KEY.$vehicleId, 1, $this->fleetThrottleSeconds());
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Deja PENDIENTE la lectura que el throttle de `fleet.position` descartó (forma primitiva). Solo se
     * conserva la última: cada descarte pisa la anterior. No lanza si Valkey falla.
     *
     * @param FleetSnapshot $snapshot
     */
    public function rememberPendingFleet(string $vehicleId, array $snapshot): void
    {
        try {
            $this->store()->put(self::FLEET_PENDING_KEY.$vehicleId, $snapshot, $this->ttlSeconds());
        } catch (Throwable) {
            // Sin pendiente no hay emisión final: `fleet/live` sigue siendo la fuente de verdad.
        }
    }

    /**
     * Lectura pendiente de emitir del vehículo, o null si no hay (o Valkey no responde).
     *
     * @return FleetSnapshot|null
     */
    public function pendingFleet(string $vehicleId): ?array
    {
        try {
            $value = $this->store()->get(self::FLEET_PENDING_KEY.$vehicleId);
            if (!is_array($value) || !is_string($value['vehicle_id'] ?? null) || !is_string($value['recorded_at'] ?? null)) {
                return null;
            }

            /** @var FleetSnapshot $value */
            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    /** Descarta la lectura pendiente del vehículo (ya salió, o ya no hace falta). */
    public function forgetPendingFleet(string $vehicleId): void
    {
        try {
            $this->store()->forget(self::FLEET_PENDING_KEY.$vehicleId);
        } catch (Throwable) {
            // Vence sola con su TTL.
        }
    }

    /**
     * Anota el instante de la lectura (`recorded_at`) del último `fleet.position` difundido del vehículo:
     * contra él se decide si una lectura pendiente todavía aporta algo.
     */
    public function markFleetEmitted(string $vehicleId, string $recordedAt): void
    {
        try {
            $this->store()->put(self::FLEET_LAST_KEY.$vehicleId, $recordedAt, $this->ttlSeconds());
        } catch (Throwable) {
            // Sin la marca, la pendiente se considera más nueva: a lo sumo se emite una posición de más.
        }
    }

    /** Instante de la lectura del último `fleet.position` difundido, o null si no se conoce. */
    public function lastFleetEmittedAt(string $vehicleId): ?string
    {
        try {
            $value = $this->store()->get(self::FLEET_LAST_KEY.$vehicleId);

            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reserva la emisión final del vehículo: devuelve true solo a quien la reserva primero (`add()` atómico),
     * así hay como mucho UN trabajo de emisión final en espera por vehículo. La marca vence sola (tres
     * ventanas) por si el trabajo se pierde. Si Valkey no responde no se reserva nada (false): sin el
     * acelerador no se programan trabajos.
     */
    public function claimTrailingFleet(string $vehicleId): bool
    {
        try {
            return $this->store()->add(self::FLEET_TRAILING_KEY.$vehicleId, 1, $this->fleetThrottleSeconds() * 3);
        } catch (Throwable) {
            return false;
        }
    }

    /** Libera la reserva de la emisión final: desde acá, un descarte nuevo programa su propio trabajo. */
    public function releaseTrailingFleet(string $vehicleId): void
    {
        try {
            $this->store()->forget(self::FLEET_TRAILING_KEY.$vehicleId);
        } catch (Throwable) {
            // Vence sola con su TTL.
        }
    }

    /** Ventana (segundos) del throttle de `fleet.position`: es también la espera de la emisión final. */
    public function fleetWindowSeconds(): int
    {
        return $this->fleetThrottleSeconds();
    }

    /**
     * Borra el estado caliente COMPLETO del vehículo (última posición, marcas de throttle, lectura pendiente y
     * reserva de emisión final). Se usa cuando el camión se da de baja en flota. No lanza si Valkey falla.
     */
    public function forget(string $vehicleId): void
    {
        $keys = [
            self::POSITION_KEY,
            self::EMIT_KEY,
            self::FLEET_EMIT_KEY,
            self::FLEET_PENDING_KEY,
            self::FLEET_LAST_KEY,
            self::FLEET_TRAILING_KEY,
        ];

        foreach ($keys as $prefix) {
            try {
                $this->store()->forget($prefix.$vehicleId);
            } catch (Throwable) {
                // Lo que no se pudo borrar vence solo con su TTL.
            }
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

    private function fleetThrottleSeconds(): int
    {
        return max(1, (int) config('logistics-telemetry.live.fleet_throttle_seconds', 5));
    }
}
