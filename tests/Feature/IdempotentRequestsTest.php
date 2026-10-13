<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Verifica el middleware de idempotencia (clave Idempotency-Key) aplicado a escrituras. Se registra
 * una ruta de prueba con un contador en memoria: una ejecución real incrementa el contador; un replay
 * no. El store de caché en tests es el array store (en producción es Valkey).
 */

/**
 * Registra una ruta POST idempotente que incrementa un contador por cada ejecución REAL del handler.
 * El contador se devuelve en el cuerpo para distinguir ejecución de replay.
 */
function registerIdempotentRoute(string $uri): void
{
    $counter = (object) ['hits' => 0];

    Route::post($uri, function () use ($counter) {
        $counter->hits++;

        return response()->json(['hits' => $counter->hits], 201);
    })->middleware('idempotency');
}

it('replays the stored response for a repeated key and body', function (): void {
    registerIdempotentRoute('/api/_test/idem-replay');
    $headers = ['Idempotency-Key' => 'key-replay-1'];

    $first = $this->postJson('/api/_test/idem-replay', ['qty' => 1], $headers);
    $first->assertStatus(201)->assertJsonPath('hits', 1);

    $second = $this->postJson('/api/_test/idem-replay', ['qty' => 1], $headers);
    $second->assertStatus(201)
        ->assertJsonPath('hits', 1)          // el handler NO se ejecutó de nuevo
        ->assertHeader('Idempotency-Replayed', 'true');
});

it('rejects the same key reused with a different body', function (): void {
    registerIdempotentRoute('/api/_test/idem-reuse');
    $headers = ['Idempotency-Key' => 'key-reuse-1'];

    $this->postJson('/api/_test/idem-reuse', ['qty' => 1], $headers)->assertStatus(201);

    $this->postJson('/api/_test/idem-reuse', ['qty' => 2], $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reused');
});

it('rejects a malformed idempotency key', function (): void {
    registerIdempotentRoute('/api/_test/idem-bad');

    $this->postJson('/api/_test/idem-bad', ['qty' => 1], ['Idempotency-Key' => str_repeat('x', 300)])
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_idempotency_key');
});

it('passes writes through when no idempotency key is sent', function (): void {
    registerIdempotentRoute('/api/_test/idem-none');

    $this->postJson('/api/_test/idem-none', ['qty' => 1])->assertStatus(201)->assertJsonPath('hits', 1);
    // Sin clave no hay deduplicación: cada petición ejecuta el handler.
    $this->postJson('/api/_test/idem-none', ['qty' => 1])->assertStatus(201)->assertJsonPath('hits', 2);
});

it('does not intercept read requests', function (): void {
    $counter = (object) ['hits' => 0];

    Route::get('/api/_test/idem-get', function () use ($counter) {
        $counter->hits++;

        return response()->json(['hits' => $counter->hits]);
    })->middleware('idempotency');

    $headers = ['Idempotency-Key' => 'key-get-1'];
    $this->getJson('/api/_test/idem-get', $headers)->assertOk()->assertJsonPath('hits', 1);
    // GET no es escritura: no se deduplica, el handler corre cada vez.
    $this->getJson('/api/_test/idem-get', $headers)->assertOk()->assertJsonPath('hits', 2);
});
