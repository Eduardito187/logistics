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
 * y Leaflet-Geoman en unpkg, los tiles de OpenStreetMap y las fuentes del tema. El resto queda denegado.
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
        // Tiles OSM, íconos de marcador de Leaflet (unpkg) e imágenes embebidas (QR/etiquetas, blobs).
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
        $directives = [];

        foreach (self::CONTENT_SECURITY_POLICY as $directive => $sources) {
            $directives[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $directives);
    }
}
