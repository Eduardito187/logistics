<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Realtime;

/**
 * ¿Hay transporte de tiempo real al que emitir? Mismo criterio que la bandeja interna del host, sin
 * depender de él: se lee `broadcasting.default`.
 *
 * Con `null` (default seguro) no hay a quién emitir, y con `log` solo se llenaría el log: en ambos casos la
 * señal de notificaciones no encola ni consulta nada.
 */
final class RealtimeTransport
{
    /**
     * Transportes que no entregan nada a un cliente.
     *
     * @var list<string>
     */
    private const INERT = ['null', 'log'];

    public static function enabled(): bool
    {
        $driver = config('broadcasting.default');

        return is_string($driver) && $driver !== '' && !in_array($driver, self::INERT, true);
    }
}
