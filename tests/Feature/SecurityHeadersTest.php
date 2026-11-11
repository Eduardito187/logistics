<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Verifica el contrato de cabeceras de seguridad del admin (S-12): la CSP es 'self'-only (CERO CDN
// externo; Leaflet/Geoman y fuentes se sirven locales, los tiles desde el tileserver self-host) y
// endurece el resto; HSTS solo sobre HTTPS.

/**
 * Invoca el middleware con una petición de prueba y devuelve la respuesta ya con cabeceras.
 */
function runSecurityHeaders(Request $request): Response
{
    return (new SecurityHeaders)->handle($request, static fn (): Response => new Response('ok'));
}

it('sets the hardening headers on the response', function (): void {
    $response = runSecurityHeaders(Request::create('http://localhost/admin'));

    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull();
});

it('keeps the CSP self-only for code: no external CDN, local leaflet/geoman; only the tile origin is opened', function (): void {
    $csp = (string) runSecurityHeaders(Request::create('http://localhost/admin'))
        ->headers->get('Content-Security-Policy');

    // CERO CDN para CÓDIGO: Leaflet/Geoman y fuentes se sirven locales desde 'self'. La única excepción
    // es la imagen de las teselas: se abre en img-src el origen de config('maps.tile_url').
    expect($csp)->not->toContain('unpkg.com')
        ->and($csp)->toContain('img-src')
        ->and($csp)->toContain((string) parse_url((string) config('maps.tile_url'), PHP_URL_HOST))
        ->and($csp)->toContain("script-src 'self'")
        ->and($csp)->toContain("style-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'self'")
        // Alpine (Filament) y los scripts inline de las páginas de mapa necesitan estos dos.
        ->and($csp)->toContain("'unsafe-inline'")
        ->and($csp)->toContain("'unsafe-eval'");
});

it('default map tiles work in ANY environment without configuring anything (never localhost)', function (): void {
    // Regresión (2026-11-11): el default era `http://localhost:8082` (tileserver opcional del perfil geo) y en
    // staging/cloud el mapa quedaba SIN FONDO; solo andaba en la PC del dueño por su `.env` local. Se evalúa
    // config/maps.php SIN la variable, como en una instalación nueva.
    $saved = [getenv('MAP_TILE_URL'), $_ENV['MAP_TILE_URL'] ?? null, $_SERVER['MAP_TILE_URL'] ?? null];
    putenv('MAP_TILE_URL');
    unset($_ENV['MAP_TILE_URL'], $_SERVER['MAP_TILE_URL']);

    try {
        /** @var array{tile_url: string} $maps */
        $maps = require base_path('config/maps.php');
    } finally {
        if (is_string($saved[0])) {
            putenv('MAP_TILE_URL='.$saved[0]);
        }
        if ($saved[1] !== null) {
            $_ENV['MAP_TILE_URL'] = $saved[1];
        }
        if ($saved[2] !== null) {
            $_SERVER['MAP_TILE_URL'] = $saved[2];
        }
    }

    $host = (string) parse_url($maps['tile_url'], PHP_URL_HOST);

    expect($maps['tile_url'])->toStartWith('https://')
        ->toContain('{z}')
        ->and($host)->not->toBeIn(['localhost', '127.0.0.1', ''])
        // .env.example es lo que copia una instalación nueva (y el CI): tampoco puede apuntar a localhost.
        ->and((string) file_get_contents(base_path('.env.example')))->not->toMatch('/^MAP_TILE_URL=https?:\/\/(localhost|127\.0\.0\.1)/m');
});

it('emits HSTS only over HTTPS', function (): void {
    $insecure = runSecurityHeaders(Request::create('http://localhost/admin'));
    expect($insecure->headers->has('Strict-Transport-Security'))->toBeFalse();

    $secure = runSecurityHeaders(Request::create('https://localhost/admin'));
    expect($secure->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

it('is wired onto the admin panel responses', function (): void {
    // La página de login del admin es pública: confirma que el middleware corre en el panel.
    $response = $this->get('/admin/login');

    $response->assertSuccessful();
    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and((string) $response->headers->get('Content-Security-Policy'))->not->toContain('unpkg.com');
});

it('does not overwrite a Content-Security-Policy already set downstream', function (): void {
    $response = (new SecurityHeaders)->handle(
        Request::create('http://localhost/admin'),
        static function (): Response {
            $r = new Response('ok');
            $r->headers->set('Content-Security-Policy', "default-src 'none'");

            return $r;
        },
    );

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});
