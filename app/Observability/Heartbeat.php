<?php

declare(strict_types=1);

namespace App\Observability;

use App\Metrics\MetricsStore;
use Throwable;

/**
 * Latidos de los procesos de fondo (Ola 5): prueban que el scheduler y los workers de cola siguen vivos.
 *
 * Una cola con profundidad 0 no distingue "no hay trabajo" de "no hay nadie trabajando". El latido sí:
 * cada proceso deja su timestamp en Valkey y el collector expone la EDAD de ese timestamp.
 *
 *   - Scheduler: una tarea por minuto en routes/console.php llama a {@see scheduler()}. Si
 *     `logistics_scheduler_last_tick_age_seconds` pasa de ~120 s, el scheduler está caído o colgado.
 *   - Workers: se engancha al evento `Illuminate\Queue\Events\Looping`, que el worker dispara en CADA
 *     vuelta de su bucle, haya o no trabajos. Es la señal más barata y robusta: mide que el proceso está
 *     vivo (no solo que procesó algo), no encola nada y no depende de que entre tráfico. Para no escribir
 *     en Valkey en cada vuelta se limita a un latido cada N segundos por proceso.
 *
 * Tolerante: si Valkey falla, el latido se pierde en silencio; nunca tumba al scheduler ni al worker.
 */
final class Heartbeat
{
    /** Nombre del latido del scheduler. */
    public const SCHEDULER = 'scheduler';

    /** Prefijo del latido de un worker; le sigue el nombre real de la cola. */
    public const WORKER_PREFIX = 'worker:';

    /**
     * Instante del último latido de worker escrito por ESTE proceso. No es un dato memoizado: es solo el
     * reloj del limitador, así que es seguro que viva lo que viva el proceso.
     */
    private static int $lastWorkerBeat = 0;

    /**
     * Latido del scheduler: lo llama la tarea de cada minuto.
     */
    public static function scheduler(): void
    {
        try {
            if (!self::enabled()) {
                return;
            }

            app(MetricsStore::class)->beat(self::SCHEDULER);
        } catch (Throwable) {
            // El latido es accesorio: jamás rompe la corrida del scheduler.
        }
    }

    /**
     * Latido de un worker. `$queues` es el valor de `--queue` del worker (una o varias colas separadas
     * por coma): se marca cada una, así la etiqueta `queue` coincide con las colas reales.
     */
    public static function worker(?string $queues): void
    {
        try {
            if (!self::enabled()) {
                return;
            }

            $now = time();
            $interval = max(1, (int) config('observability.metrics.heartbeat.worker_interval', 10));

            if ($now - self::$lastWorkerBeat < $interval) {
                return;
            }

            self::$lastWorkerBeat = $now;
            $store = app(MetricsStore::class);

            foreach (explode(',', (string) $queues) as $queue) {
                $queue = trim($queue);

                if ($queue !== '') {
                    $store->beat(self::WORKER_PREFIX.$queue);
                }
            }
        } catch (Throwable) {
            // El latido es accesorio: jamás rompe el bucle del worker.
        }
    }

    private static function enabled(): bool
    {
        return (bool) config('observability.metrics.heartbeat.enabled', true);
    }
}
