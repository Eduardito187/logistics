<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Verifica el rate-limiting por puerta (S-12): el limiter `api` se empuja al grupo `api` desde
// App\Providers\AuthServiceProvider sin reescribir routes/api.php, y distingue cada puerta.

it('registers the api rate limiter', function (): void {
    expect(RateLimiter::limiter('api'))->not->toBeNull();
});

it('throttles api responses with rate-limit headers', function (): void {
    // La ruta de salud pública vive bajo el grupo `api`, así que lleva el throttle del limiter `api`.
    $response = $this->getJson('/api/v1/health');

    $response->assertOk();
    expect($response->headers->has('X-RateLimit-Limit'))->toBeTrue()
        // Puerta "resto" (health): 60/min por IP.
        ->and((int) $response->headers->get('X-RateLimit-Limit'))->toBe(60);
});

it('applies a per-token limit on the app gate', function (): void {
    // Ruta de prueba montada en el grupo `api` bajo el prefijo de la puerta app.
    Route::middleware('api')->get('/api/v1/app/__probe', fn (): string => 'ok');

    $response = $this->withToken('fake-token')->getJson('/api/v1/app/__probe');

    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(120);
});

it('applies a per-client limit on the integrations gate', function (): void {
    Route::middleware('api')->get('/api/v1/integrations/__probe', fn (): string => 'ok');

    $response = $this->withHeader('X-Client-Id', 'acme')->getJson('/api/v1/integrations/__probe');

    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(300);
});

it('does not double-limit the channels gate (handled by the channels limiter)', function (): void {
    Route::middleware('api')->get('/api/v1/channels/__probe', fn (): string => 'ok');

    $response = $this->getJson('/api/v1/channels/__probe');

    // Limit::none() → el limiter `api` no impone tope en esta puerta; no emite cabecera de límite.
    $response->assertOk();
    expect($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

it('returns 429 once the per-ip gate limit is exceeded', function (): void {
    Route::middleware('api')->get('/api/v1/__probe', fn (): string => 'ok');

    // El limiter "resto" es 60/min por IP: 60 pasan, el 61 choca.
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/__probe')->assertOk();
    }

    $blocked = $this->getJson('/api/v1/__probe');
    $blocked->assertStatus(429)
        // El 429 se rinde como problem+json (no como 500): la cabecera Retro-After entera no debe
        // romper el renderer de problemas.
        ->assertJsonPath('code', 'rate_limited');
    expect($blocked->headers->has('Retry-After'))->toBeTrue();
});
