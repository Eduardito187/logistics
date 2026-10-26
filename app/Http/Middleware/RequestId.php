<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * M-7 — Correlación de request-id de extremo a extremo.
 *
 * Toma el `X-Request-Id` entrante (si viene de un balanceador/gateway/cliente) o genera uno nuevo
 * (UUID v4), y lo fija como id de correlación para TODA la cadena de ese request:
 *
 *   1. En el `Context` de Laravel (Illuminate\Support\Context), que se propaga AUTOMÁTICAMENTE a los
 *      jobs encolados; así el id viaja gratis evento → outbox → relay → notificación y aparece en los
 *      logs de los workers que procesan esa cadena, sin tocar el dominio.
 *   2. En el contexto de logs (`Log::withContext`), para que todas las líneas del request lo lleven en
 *      `context.request_id`.
 *   3. De vuelta en la respuesta, como cabecera `X-Request-Id`, para que el cliente lo reporte.
 *
 * La MISMA clase actúa además como PROCESSOR de Monolog (implementa ProcessorInterface): el canal `json`
 * la resuelve vía el hook `LOG_REQUEST_ID_PROCESSOR` (config/logging.php) y así inyecta el id también en
 * `extra.request_id` de cada línea JSON, leyéndolo del Context —de modo que funciona igual dentro de un
 * worker/relay, donde no hay request HTTP pero sí el Context restaurado del job.
 */
final class RequestId implements ProcessorInterface
{
    /** Clave de correlación usada en Context, logs y cabecera. */
    public const CONTEXT_KEY = 'request_id';

    /** Cabecera HTTP estándar de facto para el id de correlación. */
    public const HEADER = 'X-Request-Id';

    /** Largo máximo aceptado de un id entrante (evita cabeceras abusivas). */
    private const MAX_LENGTH = 128;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        // Propagación automática a jobs encolados (outbox/notifications) + disponible para el processor.
        Context::add(self::CONTEXT_KEY, $requestId);
        // Todas las líneas de log del request llevan el id en `context`.
        Log::withContext([self::CONTEXT_KEY => $requestId]);

        /** @var Response $response */
        $response = $next($request);

        // Devolver el id al cliente para cerrar el lazo de correlación.
        if (!$response->headers->has(self::HEADER)) {
            $response->headers->set(self::HEADER, $requestId);
        }

        return $response;
    }

    /**
     * Processor de Monolog: añade el id de correlación a `extra.request_id` de cada registro,
     * leyéndolo del Context (presente tanto en un request HTTP como en un job encolado restaurado).
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = Context::get(self::CONTEXT_KEY);

        if (!is_string($requestId) || $requestId === '') {
            return $record;
        }

        $record->extra[self::CONTEXT_KEY] = $requestId;

        return $record;
    }

    /**
     * Devuelve el id entrante saneado o genera uno nuevo. Se acepta solo un token acotado y seguro
     * ([A-Za-z0-9._-]) para no reflejar cabeceras arbitrarias en logs/respuesta.
     */
    private function resolveRequestId(Request $request): string
    {
        $incoming = $request->headers->get(self::HEADER);

        if (is_string($incoming)) {
            $incoming = trim($incoming);
            if ($incoming !== ''
                && strlen($incoming) <= self::MAX_LENGTH
                && preg_match('/^[A-Za-z0-9._-]+$/', $incoming) === 1
            ) {
                return $incoming;
            }
        }

        return (string) Str::uuid();
    }
}
