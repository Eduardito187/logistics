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
 * que permite exactamente lo que el panel usa: assets propios de Filament/Livewire, los CDN de Leaflet
 * y Leaflet-Geoman en unpkg, las teselas de mapas (el tileserver OSM self-host configurado en
 * config/maps.php más el tile público de OSM como fallback) y las fuentes del tema. El resto queda denegado.
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
        // Scripts propios + inline/eval (Alpine/Livewire) + Leaflet y Geoman desde unpkg.
        'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://unpkg.com'],
        // Estilos propios + inline (tema de marca, Leaflet) + CSS de Leaflet/Geoman y fuentes.
        'style-src' => ["'self'", "'unsafe-inline'", 'https://unpkg.com', 'https://fonts.bunny.net', 'https://fonts.googleapis.com'],
        // Teselas de mapas (OSM público de fallback), íconos de marcador de Leaflet (unpkg) e imágenes
        // embebidas (QR/etiquetas, blobs). El origen del tileserver self-host se agrega en runtime desde
        // config/maps.php (ver contentSecurityPolicy): así el admin funciona con tiles propios sin editar el código.
        'img-src' => ["'self'", 'data:', 'blob:', 'https://unpkg.com', 'https://*.tile.openstreetmap.org'],
        'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net', 'https://fonts.gstatic.com'],
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
