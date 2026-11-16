<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Realtime;

/**
 * Parámetros de la señal de tiempo real de las alertas de telemetría (`alerts.updated`), leídos de la config
 * en cada llamada (sin estado en memoria: seguro con workers de larga vida, Octane).
 *
 * ¿Hay a quién emitir? Mismo criterio que el resto de las señales de la plataforma: se lee
 * `broadcasting.default`. Con `null` (default seguro) no hay transporte, y con `log` solo se llenaría el log:
 * en ambos casos la señal no encola ni consulta nada. Un interruptor propio
 * (`logistics-telemetry.realtime.alerts.enabled`, por defecto activo) permite apagarla sin tocar el transporte.
 */
final class AlertsRealtime
{
    /** Ventana anti-ráfaga por defecto, en segundos. */
    public const int DEFAULT_DEBOUNCE_SECONDS = 2;

    /**
     * Transportes que no entregan nada a un cliente.
     *
     * @var list<string>
     */
    private const array INERT = ['null', 'log'];

    public static function enabled(): bool
    {
        $driver = config('broadcasting.default');

        return config('logistics-telemetry.realtime.alerts.enabled', true) !== false
            && is_string($driver)
            && $driver !== ''
            && !in_array($driver, self::INERT, true);
    }

    /**
     * Ventana (segundos) en la que varios cambios de la misma alerta se avisan una sola vez; es también la
     * demora del trabajo, para que al llegar la señal el estado vigente ya incluya la ráfaga completa.
     */
    public static function debounceSeconds(): int
    {
        $seconds = config('logistics-telemetry.realtime.alerts.debounce_seconds', self::DEFAULT_DEBOUNCE_SECONDS);

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : self::DEFAULT_DEBOUNCE_SECONDS;
    }
}
