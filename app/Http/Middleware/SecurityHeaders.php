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
 * Política de CERO CDN externo: todo se sirve desde 'self'. El resto queda denegado.
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
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
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
    private function contentSecurityPolicy(): string
    {
        $policy = self::CONTENT_SECURITY_POLICY;

        // Agrega el origen del tileserver OSM self-host (config/maps.php) a img-src, para que las teselas
        // propias carguen sin relajar la política. Si no hay URL configurada, se queda solo el OSM público.
        $tileOrigin = $this->selfHostTileOrigin();
        if ($tileOrigin !== null && !in_array($tileOrigin, $policy['img-src'], true)) {
            $policy['img-src'][] = $tileOrigin;
        }

        $directives = [];
        foreach ($policy as $directive => $sources) {
            $directives[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $directives);
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
