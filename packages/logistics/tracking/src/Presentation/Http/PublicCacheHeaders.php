<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de caché para las respuestas PÚBLICAS por token (JSON de canal y página HTML).
 *
 * `Cache-Control: public, max-age=<corto>, stale-while-revalidate=<n>` + `ETag`: el navegador, un CDN o
 * el micro-caché de nginx absorben el polling sin llegar a PHP, y con `If-None-Match` coincidente se
 * responde 304 sin cuerpo. Es seguro porque la respuesta no lleva datos personales y la única
 * credencial del enlace es el token de la URL (que forma parte de la clave de cualquier caché).
 *
 * Solo para visitantes ANÓNIMOS: si el request trae un usuario autenticado, `Authorization` o la
 * respuesta fija cookies, NO se marca como pública (queda el `private`/`no-cache` por defecto). El
 * `ETag` y el 304 se conservan igual, porque valen también para un caché privado.
 */
trait PublicCacheHeaders
{
    /**
     * Aplica `ETag` (y `Cache-Control` público si corresponde) y resuelve el 304.
     *
     * @template TResponse of Response
     *
     * @param  TResponse $response
     * @return TResponse
     */
    private function withPublicCache(Request $request, Response $response, string $etag): Response
    {
        $response->setEtag($etag);

        if ($this->canBePubliclyCached($request, $response)) {
            $response->setPublic();
            $response->setMaxAge(max(0, (int) config('logistics-tracking.public_http.max_age', 20)));
            $response->setStaleWhileRevalidate(
                max(0, (int) config('logistics-tracking.public_http.stale_while_revalidate', 30)),
            );
        }

        // Con `If-None-Match` coincidente deja la respuesta en 304 y sin cuerpo.
        $response->isNotModified($request);

        return $response;
    }

    private function canBePubliclyCached(Request $request, Response $response): bool
    {
        if (!(bool) config('logistics-tracking.public_http.enabled', true)) {
            return false;
        }

        // El chequeo barato primero: sin cabecera de credenciales ni cookies recién se consulta el guard.
        return !$request->headers->has('Authorization')
            && $response->headers->getCookies() === []
            && $request->user() === null;
    }
}
