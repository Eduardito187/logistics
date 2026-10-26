<?php

declare(strict_types=1);

namespace App\Providers;

use App\Logging\SlowQueryListener;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Registra el listener de consultas lentas (auditoría M-6).
 *
 * El cableado vive en un ServiceProvider propio (y no en config/*) a propósito: config/logging.php y
 * config/database.php los tocan otros frentes, y un `DB::listen` global encaja mejor en el arranque de
 * la app. Toda la configuración entra por env para no editar config/*:
 *
 *   - SLOW_QUERY_LOG_ENABLED     (bool, default false) activa el listener. Apagado por defecto para no
 *                                loguear en cada test ni en desarrollo normal.
 *   - SLOW_QUERY_THRESHOLD_MS    (float, default 500) umbral en milisegundos; por debajo no se registra.
 *   - SLOW_QUERY_LOG_CHANNEL     (string, default: canal por defecto) canal de log estructurado destino
 *                                (p. ej. `json` para una línea JSON por consulta lenta).
 */
final class SlowQueryLoggingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $enabled = (bool) config('observability.slow_query.enabled', false);

        // Si está apagado no registramos el listener siquiera: coste cero cuando no se usa.
        if (!$enabled) {
            return;
        }

        $threshold = (float) config('observability.slow_query.threshold_ms', 500);
        $channel = config('observability.slow_query.channel');

        $logger = is_string($channel) && $channel !== ''
            ? Log::channel($channel)
            : Log::driver();

        $listener = new SlowQueryListener($logger, true, $threshold);

        DB::listen(static function (QueryExecuted $event) use ($listener): void {
            $listener($event);
        });
    }
}
