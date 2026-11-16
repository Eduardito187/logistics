<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Realtime;

/**
 * Parámetros de la señal de tiempo real de la hoja de ruta (`route.updated`), leídos de la config en cada
 * llamada (sin estado en memoria: seguro con workers de larga vida).
 *
 * ¿Hay a quién emitir? Mismo criterio que el resto de las señales de la plataforma, sin depender de ellas:
 * se lee `broadcasting.default`. Con `null` (default seguro) no hay transporte, y con `log` solo se
 * llenaría el log: en ambos casos la señal no encola ni consulta nada.
 */
final class RouteRealtime
{
    /** Ventana anti-ráfaga por defecto, en segundos. */
    public const DEFAULT_DEBOUNCE_SECONDS = 2;

    /**
     * Throttle por defecto (segundos) del aviso por MOVIMIENTO del camión: a lo sumo una señal `route.updated`
     * por ruta cada tanto, para no inundar con cada ping. El movimiento no cambia la hoja de ruta, solo la
     * ETA viva; la app re-pide la ruta y obtiene ETAs frescas. Separado de la ventana anti-ráfaga de los
     * cambios de parada (esos sí deben avisar enseguida).
     */
    public const DEFAULT_MOVEMENT_THROTTLE_SECONDS = 20;

    /**
     * Transportes que no entregan nada a un cliente.
     *
     * @var list<string>
     */
    private const INERT = ['null', 'log'];

    public static function enabled(): bool
    {
        $driver = config('broadcasting.default');

        return config('logistics-execution.realtime.enabled', true) !== false
            && is_string($driver)
            && $driver !== ''
            && !in_array($driver, self::INERT, true);
    }

    /**
     * Ventana (segundos) en la que varios cambios de una misma ruta se avisan una sola vez; es también la
     * demora del trabajo, para que al llegar la señal la ruta ya incluya toda la ráfaga.
     */
    public static function debounceSeconds(): int
    {
        $seconds = config('logistics-execution.realtime.debounce_seconds', self::DEFAULT_DEBOUNCE_SECONDS);

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : self::DEFAULT_DEBOUNCE_SECONDS;
    }

    /**
     * Throttle (segundos) del aviso por movimiento del camión: una señal por ruta como mucho cada tanto.
     */
    public static function movementThrottleSeconds(): int
    {
        $seconds = config('logistics-execution.realtime.movement_throttle_seconds', self::DEFAULT_MOVEMENT_THROTTLE_SECONDS);

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : self::DEFAULT_MOVEMENT_THROTTLE_SECONDS;
    }
}
