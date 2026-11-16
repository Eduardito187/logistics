<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Realtime;

/**
 * Parámetros de la señal de tiempo real del seguimiento del PEDIDO (`tracking.updated`), leídos de la config
 * en cada llamada (sin estado en memoria: seguro con workers de larga vida, Octane).
 *
 * ¿Hay a quién emitir? Mismo criterio que el resto de las señales de la plataforma, sin depender de ellas:
 * se lee `broadcasting.default`. Con `null` (default seguro) no hay transporte, y con `log` solo se llenaría
 * el log: en ambos casos la señal no encola ni consulta nada.
 */
final class TrackingRealtime
{
    /** Ventana anti-ráfaga por defecto, en segundos. */
    public const DEFAULT_DEBOUNCE_SECONDS = 2;

    /**
     * Transportes que no entregan nada a un cliente.
     *
     * @var list<string>
     */
    private const INERT = ['null', 'log'];

    public static function enabled(): bool
    {
        $driver = config('broadcasting.default');

        return config('logistics-tracking.realtime.enabled', true) !== false
            && is_string($driver)
            && $driver !== ''
            && !in_array($driver, self::INERT, true);
    }

    /**
     * Ventana (segundos) en la que varios cambios de un mismo pedido se avisan una sola vez; es también la
     * demora del trabajo, para que al llegar la señal el estado vigente ya incluya la ráfaga completa.
     */
    public static function debounceSeconds(): int
    {
        $seconds = config('logistics-tracking.realtime.debounce_seconds', self::DEFAULT_DEBOUNCE_SECONDS);

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : self::DEFAULT_DEBOUNCE_SECONDS;
    }
}
