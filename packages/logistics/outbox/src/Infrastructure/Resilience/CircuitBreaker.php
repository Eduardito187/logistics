<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Resilience;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Infrastructure\Clock\SystemClock;
use Throwable;

/**
 * Circuit breaker por destino externo, con estado en Valkey (cache). Aísla fallos: si un destino
 * (SAP, FCM, Telegram, email, SMS, ...) acumula N fallos seguidos dentro de una ventana, el circuito se
 * ABRE y los siguientes intentos cortan rápido (fast-fail) mientras dura el enfriamiento, en vez de
 * gastar el timeout completo uno por uno. Pasado el enfriamiento entra en HALF-OPEN: deja pasar un
 * intento de prueba; si sale bien, cierra (se resetea); si vuelve a fallar, reabre.
 *
 * Esto implementa A-5 de la auditoría: un destino caído deja de consumir el lote y de frenar a los
 * destinos sanos. Vive en Infrastructure (usa cache/reloj del framework); el Domain no lo conoce.
 *
 * El estado es compartido entre workers (Valkey), así que el conteo de fallos y el corte valen para
 * TODOS los procesos que despachan ese destino, no solo para uno.
 */
final readonly class CircuitBreaker
{
    public function __construct(
        private Cache $cache,
        private Clock $clock,
        /** Fallos seguidos que abren el circuito. */
        private int $failureThreshold,
        /** Segundos que el circuito permanece abierto antes de pasar a half-open. */
        private int $cooldownSeconds,
        /** Ventana (segundos) en la que se cuentan los fallos seguidos. */
        private int $windowSeconds,
        /** Prefijo de las claves de cache (namespacing). */
        private string $prefix,
    ) {}

    /**
     * Construye un breaker leyendo los umbrales de config y usando el cache/clock del framework.
     * Lo usan los clientes HTTP externos, que no reciben el breaker por inyección (lo arman acá).
     */
    public static function forGroup(string $group): self
    {
        $clock = app()->bound(Clock::class) ? app(Clock::class) : new SystemClock;
        assert($clock instanceof Clock);

        return new self(
            CacheFacade::store(),
            $clock,
            (int) config('logistics-outbox.breaker.failure_threshold', 5),
            (int) config('logistics-outbox.breaker.cooldown_seconds', 30),
            (int) config('logistics-outbox.breaker.window_seconds', 60),
            'logistics-outbox:breaker:'.$group,
        );
    }

    /**
     * Ejecuta la operación a través del breaker. Si el circuito está abierto, lanza CircuitOpenException
     * sin siquiera intentar (fast-fail). Si está cerrado/half-open, ejecuta: éxito cierra el circuito,
     * fallo lo cuenta (y lo abre al llegar al umbral) y re-lanza la excepción original.
     *
     * @template T
     *
     * @param  Closure(): T $operation
     * @return T
     */
    public function call(string $destination, Closure $operation): mixed
    {
        if (!$this->allows($destination)) {
            throw CircuitOpenException::forDestination($destination);
        }

        try {
            $result = $operation();
        } catch (Throwable $error) {
            $this->recordFailure($destination);

            throw $error;
        }

        $this->recordSuccess($destination);

        return $result;
    }

    /**
     * ¿Se permite intentar el destino ahora? Falso solo mientras el circuito esté dentro de su ventana
     * de enfriamiento (abierto). Pasado el enfriamiento vuelve a permitir (half-open).
     */
    public function allows(string $destination): bool
    {
        $openUntil = (int) $this->cache->get($this->openKey($destination), 0);

        return $this->clock->now()->getTimestamp() >= $openUntil;
    }

    /**
     * Registra un fallo del destino. Al alcanzar el umbral de fallos seguidos, abre el circuito por el
     * tiempo de enfriamiento.
     */
    public function recordFailure(string $destination): void
    {
        $failKey = $this->failKey($destination);
        $failures = (int) $this->cache->get($failKey, 0) + 1;

        // El contador vive al menos lo que dure el enfriamiento, para que un fallo en half-open reabra.
        $ttl = max($this->windowSeconds, $this->cooldownSeconds) + 5;
        $this->cache->put($failKey, $failures, $ttl);

        if ($failures >= $this->failureThreshold) {
            $openUntil = $this->clock->now()->getTimestamp() + $this->cooldownSeconds;
            $this->cache->put($this->openKey($destination), $openUntil, $this->cooldownSeconds + 5);
        }
    }

    /**
     * Registra un éxito del destino: cierra el circuito y resetea el conteo de fallos.
     */
    public function recordSuccess(string $destination): void
    {
        $this->cache->forget($this->failKey($destination));
        $this->cache->forget($this->openKey($destination));
    }

    private function failKey(string $destination): string
    {
        return $this->prefix.':fails:'.$destination;
    }

    private function openKey(string $destination): string
    {
        return $this->prefix.':open:'.$destination;
    }
}
