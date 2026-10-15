<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Verifica el manejo de errores UNIFORME (RFC 7807) en las peticiones api/*. Se registran rutas de
 * prueba efímeras que lanzan cada tipo de excepción; el renderizador global las traduce a problem+json.
 */

it('renders a missing model as a 404 problem document', function (): void {
    Route::get('/api/_test/missing-model', fn () => throw new ModelNotFoundException);

    $response = $this->getJson('/api/_test/missing-model')
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('type', '/problems/not_found')
        ->assertJsonPath('instance', '/api/_test/missing-model');

    expect((string) $response->headers->get('Content-Type'))->toContain('application/problem+json');
});

it('renders a NotFoundHttpException as a 404 problem document', function (): void {
    Route::get('/api/_test/not-found', fn () => throw new NotFoundHttpException);

    $this->getJson('/api/_test/not-found')
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});

it('renders validation failures as a 422 problem with field errors', function (): void {
    Route::post('/api/_test/validate', function (Request $request) {
        $request->validate(['name' => 'required']);

        return response()->json(['ok' => true]);
    });

    $response = $this->postJson('/api/_test/validate', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('status', 422);

    expect($response->json('errors.name'))->toBeArray()->not->toBeEmpty();
    expect((string) $response->headers->get('Content-Type'))->toContain('application/problem+json');
});

it('renders an authentication failure as a 401 problem', function (): void {
    Route::get('/api/_test/auth', fn () => throw new AuthenticationException);

    $this->getJson('/api/_test/auth')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});

it('renders an authorization failure as a 403 problem', function (): void {
    Route::get('/api/_test/forbidden', fn () => throw new AuthorizationException('Nope.'));

    $this->getJson('/api/_test/forbidden')
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});

it('renders an unexpected exception as a 500 problem without leaking internals', function (): void {
    // En modo no-debug el detalle queda oculto; forzamos debug=false para la aserción.
    config(['app.debug' => false]);

    Route::get('/api/_test/boom', fn () => throw new RuntimeException('internal secret detail'));

    $response = $this->getJson('/api/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('code', 'server_error');

    expect($response->json('detail'))->not->toBe('internal secret detail');
});

it('leaves non-api requests untouched by the problem renderer', function (): void {
    Route::get('/web-test/not-found', fn () => throw new NotFoundHttpException);

    // Una petición web (no api/*) no recibe problem+json: el renderizador devuelve null.
    $response = $this->get('/web-test/not-found');

    expect((string) $response->headers->get('Content-Type'))->not->toContain('application/problem+json');
});
