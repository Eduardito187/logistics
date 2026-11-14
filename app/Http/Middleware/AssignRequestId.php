<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ola 5 — Asigna el id de correlación del request (primer middleware de la pila).
 *
 * Toma el `X-Request-Id` entrante (balanceador, gateway o cliente) si tiene un formato seguro, o genera
 * un UUID v7 (ordenable en el tiempo, como el resto de ids de la plataforma). Con ese id:
 *
 *   1. Lo guarda en el `Context` de Laravel bajo `request_id`. El framework agrega el Context al `extra`
 *      de CADA línea de log (cualquier canal, incluido `json`) y lo serializa en el payload de los jobs
 *      encolados, restaurándolo en el worker: el id viaja solo request → outbox → relay → notificación.
 *   2. Lo agrega al contexto de logs del request (`context.request_id`), por compatibilidad con las
 *      consultas del agregador que ya filtran por ese campo.
 *   3. Lo devuelve en la cabecera `X-Request-Id` de la respuesta, para que el cliente lo reporte.
 *
 * Reemplaza en la pila al middleware {@see RequestId} (que generaba UUID v4); esa clase sigue siendo el
 * processor de Monolog del canal `json` y comparte la misma clave de Context.
 */
final class AssignRequestId
{
    /** Clave de correlación en el Context y en los logs. */
    public const CONTEXT_KEY = 'request_id';

    /** Cabecera HTTP estándar de facto para el id de correlación. */
    public const HEADER = 'X-Request-Id';

    /**
     * Formato aceptado de un id entrante: token acotado de caracteres seguros. Evita reflejar cabeceras
     * arbitrarias (inyección de logs/cabeceras) y cabeceras abusivamente largas.
     */
    private const PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolve($request);

        Context::add(self::CONTEXT_KEY, $requestId);
        Log::withContext([self::CONTEXT_KEY => $requestId]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * Id entrante si es válido; si no, uno nuevo.
     */
    private function resolve(Request $request): string
    {
        $incoming = trim((string) $request->headers->get(self::HEADER, ''));

        if (preg_match(self::PATTERN, $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::uuid7();
    }
}
