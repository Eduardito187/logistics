<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Realtime;

/**
 * Parámetros de la señal de tiempo real de la carga del camión (`loading.updated`), leídos de la config en
 * cada llamada (sin estado en memoria: seguro con workers de larga vida / Octane).
 *
 * Comparte el interruptor maestro y el criterio de transporte con el resto del tiempo real de ejecución: se
 * reutiliza {@see RouteRealtime::enabled()} (solo lectura) para no duplicar la lógica de `broadcasting.default`
 * null/log ni el flag `realtime.enabled`. La demora anti-ráfaga es propia de esta señal.
 */
final class LoadingRealtime
{
    /** Ventana anti-ráfaga por defecto, en segundos. */
    public const DEFAULT_DEBOUNCE_SECONDS = 2;

    /**
     * ¿Hay transporte de sockets y la señal de ejecución está activa? Mismo criterio que `route.updated`
     * (interruptor `realtime.enabled` + `broadcasting.default` distinto de null/log): con el default seguro no
     * encola ni consulta nada.
     */
    public static function enabled(): bool
    {
        return RouteRealtime::enabled();
    }

    /**
     * Ventana (segundos) en la que varios cambios de la carga de un mismo camión se avisan una sola vez; es
     * también la demora del trabajo, para que al llegar la señal la hoja de carga ya incluya toda la ráfaga.
     */
    public static function debounceSeconds(): int
    {
        $seconds = config('logistics-execution.realtime.loading_debounce_seconds', self::DEFAULT_DEBOUNCE_SECONDS);

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : self::DEFAULT_DEBOUNCE_SECONDS;
    }
}
