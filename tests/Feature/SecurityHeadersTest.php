<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Verifica el contrato de cabeceras de seguridad del admin (S-12): la CSP permite lo que el panel
// usa (Leaflet/Geoman en unpkg, tiles OSM) y endurece el resto; HSTS solo sobre HTTPS.

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

it('allows leaflet, geoman and osm tiles in the CSP', function (): void {
    $csp = (string) runSecurityHeaders(Request::create('http://localhost/admin'))
        ->headers->get('Content-Security-Policy');

    // Scripts y estilos de Leaflet/Geoman vienen de unpkg; los tiles de OpenStreetMap.
    expect($csp)->toContain('https://unpkg.com')
        ->and($csp)->toContain('https://*.tile.openstreetmap.org')
        ->and($csp)->toContain("frame-ancestors 'self'")
        // Alpine (Filament) y los scripts inline de las páginas de mapa necesitan estos dos.
        ->and($csp)->toContain("'unsafe-inline'")
        ->and($csp)->toContain("'unsafe-eval'");
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
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain('https://unpkg.com');
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
