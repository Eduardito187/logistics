<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad del panel de administración (S-12).
 *
 * Endurece el admin contra clickjacking, sniffing y fuga de referrer, y fija una Content-Security-Policy
 * que permite exactamente lo que el panel usa: assets propios de Filament/Livewire, Leaflet y
 * Leaflet-Geoman servidos LOCALES desde 'self' (vendorizados en public/vendor), las teselas del
 * tileserver OSM self-host (config/maps.php; sin fallback público) y las fuentes del tema auto-hospedadas.
 * Política de CERO CDN externo: cada recurso se sirve desde 'self'. El resto queda denegado.
 *
 * La CSP permite 'unsafe-inline' y 'unsafe-eval' en scripts a propósito: Alpine (base de Filament)
 * evalúa expresiones con `new Function`, y las páginas de mapa incrustan scripts inline. No se usan
 * nonces porque conviven con esos dos requisitos.
 *
 * HSTS solo se emite sobre HTTPS (inútil y contraproducente en texto plano, p. ej. en local).
 */
final class SecurityHeaders
{
    /**
     * Política de contenido: una lista de directivas → orígenes permitidos.
     *
     * @var array<string, list<string>>
     */
    private const CONTENT_SECURITY_POLICY = [
        'default-src' => ["'self'"],
        // Scripts propios + inline/eval (Alpine/Livewire). Leaflet y Geoman se sirven LOCALES desde
        // 'self' (vendorizados en public/vendor); sin ningún CDN externo (política de cero CDN).
        'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
        // Estilos propios + inline (tema de marca, Leaflet/Geoman locales). Las fuentes de marca
        // (Barlow, Barlow Condensed, IBM Plex Mono) se auto-hospedan como woff2 desde assets propios
        // ('self'); no se usa Google Fonts (ADR 013 "sin Google").
        'style-src' => ["'self'", "'unsafe-inline'"],
        // Imágenes propias, íconos de marcador de Leaflet (locales en public/vendor) e imágenes embebidas
        // (QR/etiquetas, blobs) + data:. El origen del tileserver self-host se agrega en runtime desde
        // config/maps.php (ver contentSecurityPolicy): tiles propios, sin fallback a tiles públicos de OSM.
        'img-src' => ["'self'", 'data:', 'blob:'],
        // Fuentes de marca (Barlow, Barlow Condensed, IBM Plex Mono) auto-hospedadas como woff2 bajo
        // 'self' + data: para íconos inline. Sin Google Fonts ni ningún CDN.
        'font-src' => ["'self'", 'data:'],
        // Peticiones XHR/fetch de Livewire: mismo origen.
        'connect-src' => ["'self'"],
        'worker-src' => ["'self'", 'blob:'],
        'frame-ancestors' => ["'self'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'object-src' => ["'none'"],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request));
        }

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS solo tiene sentido sobre HTTPS (un navegador lo ignora en HTTP y fijarlo en local estorba).
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Arma la cabecera CSP a partir del mapa de directivas.
     */
    private function contentSecurityPolicy(Request $request): string
    {
        $policy = self::CONTENT_SECURITY_POLICY;

        // Agrega el origen del tileserver OSM self-host (config/maps.php) a img-src, para que las teselas
        // propias carguen sin relajar la política. Si no hay URL configurada, se queda solo el OSM público.
        $tileOrigin = $this->selfHostTileOrigin();
        if ($tileOrigin !== null && !in_array($tileOrigin, $policy['img-src'], true)) {
            $policy['img-src'][] = $tileOrigin;
        }

        // Si el broadcasting en vivo está activo (Soketi vía protocolo Pusher), permite el WebSocket del
        // cliente en connect-src: el del mismo origen de la petición (y el override, si lo hay). Solo se
        // agrega cuando está habilitado, para no relajar la CSP de más.
        foreach ($this->broadcastConnectOrigins($request) as $origin) {
            if (!in_array($origin, $policy['connect-src'], true)) {
                $policy['connect-src'][] = $origin;
            }
        }

        $directives = [];
        foreach ($policy as $directive => $sources) {
            $directives[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $directives);
    }

    /**
     * Orígenes a permitir en connect-src para el WebSocket del cliente (Soketi), SOLO si el broadcasting
     * en vivo está activo (BROADCAST_CONNECTION=pusher). Vacío si está apagado.
     *
     * Por defecto el servidor de sockets se publica por el MISMO host y puerto que la web (nginx desvía
     * `/app/<clave>`), así que se permite el ws(s):// del origen de la petición actual: `'self'` no cubre
     * ws/wss en todos los navegadores. No se agrega ningún host fijo (nada de localhost:6001).
     *
     * Solo si `broadcasting.client_ws` trae un override explícito (VITE_PUSHER_HOST/PORT) se suma además
     * ese origen, en ws(s):// y http(s):// (pusher-js usa el WebSocket y, según el entorno, una
     * verificación HTTP).
     *
     * @return list<string>
     */
    private function broadcastConnectOrigins(Request $request): array
    {
        if (config('broadcasting.default') !== 'pusher') {
            return [];
        }

        // Mismo origen de la petición. getHttpHost() e isSecure() ya respetan los proxies de confianza
        // (X-Forwarded-Host/Port/Proto) y omiten el puerto cuando es el estándar del esquema.
        $origins = [($request->isSecure() ? 'wss' : 'ws').'://'.$request->getHttpHost()];

        // Override explícito: Soketi publicado aparte (p. ej. desarrollo con el 6001 directo).
        $configuredHost = config('broadcasting.client_ws.host');
        $configuredPort = config('broadcasting.client_ws.port');
        $scheme = config('broadcasting.client_ws.scheme');
        $host = is_string($configuredHost) && $configuredHost !== '' ? $configuredHost : null;
        $port = is_int($configuredPort) && $configuredPort > 0 ? $configuredPort : null;
        if ($host === null && $port === null) {
            return $origins;
        }

        // Host sin fijar + puerto fijado = el host de la página en ese puerto (igual que echo.js).
        $secure = is_string($scheme) && $scheme !== '' ? $scheme === 'https' : $request->isSecure();
        $authority = ($host ?? $request->getHost()).($port !== null ? ':'.$port : '');

        $origins[] = ($secure ? 'wss' : 'ws').'://'.$authority;
        $origins[] = ($secure ? 'https' : 'http').'://'.$authority;

        return $origins;
    }

    /**
     * Origen (esquema://host[:puerto]) del tileserver self-host configurado, o null si no hay uno o es
     * del mismo origen ('self' ya lo cubre). Se deriva de la plantilla de teselas de config/maps.php.
     */
    private function selfHostTileOrigin(): ?string
    {
        $tileUrl = config('maps.tile_url');
        if (!is_string($tileUrl) || $tileUrl === '') {
            return null;
        }

        $scheme = parse_url($tileUrl, PHP_URL_SCHEME);
        $host = parse_url($tileUrl, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($tileUrl, PHP_URL_PORT);
        $origin = $scheme.'://'.$host;
        if (is_int($port)) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}
