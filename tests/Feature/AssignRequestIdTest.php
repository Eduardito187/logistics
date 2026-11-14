<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Id de correlación (Ola 5): respeta un X-Request-Id entrante válido, genera un UUID v7 si falta o es
// inválido, lo deja en el Context (de ahí pasa solo a logs y jobs) y lo devuelve en la respuesta.

/**
 * Ejecuta el middleware con el header dado y devuelve la respuesta.
 */
function runAssignRequestId(?string $incoming): Response
{
    $request = Request::create('/x', 'GET');

    if ($incoming !== null) {
        $request->headers->set('X-Request-Id', $incoming);
    }

    return (new AssignRequestId)->handle($request, static fn (Request $request): Response => new Response('ok', 200));
}

it('keeps a valid incoming X-Request-Id', function (): void {
    $response = runAssignRequestId('gateway-abc_123.45');

    expect($response->headers->get('X-Request-Id'))->toBe('gateway-abc_123.45')
        ->and(Context::get('request_id'))->toBe('gateway-abc_123.45');
});

it('generates a UUID v7 when the header is missing', function (): void {
    $response = runAssignRequestId(null);
    $requestId = (string) $response->headers->get('X-Request-Id');

    expect(Str::isUuid($requestId))->toBeTrue()
        // El dígito de versión de un UUID es el primero del tercer grupo.
        ->and($requestId[14])->toBe('7')
        ->and(Context::get('request_id'))->toBe($requestId);
});

it('replaces an incoming id with unsafe characters', function (string $incoming): void {
    $response = runAssignRequestId($incoming);
    $requestId = (string) $response->headers->get('X-Request-Id');

    expect($requestId)->not->toBe($incoming)
        ->and(Str::isUuid($requestId))->toBeTrue();
})->with([
    'espacios' => ['abc def'],
    'inyección de log' => ["abc\ninjected"],
    'símbolos' => ['<script>'],
    'demasiado largo' => [str_repeat('a', 129)],
]);

it('accepts an incoming id at the maximum length', function (): void {
    $incoming = str_repeat('a', 128);

    expect(runAssignRequestId($incoming)->headers->get('X-Request-Id'))->toBe($incoming);
});

it('is applied to every HTTP response, echoing the incoming id', function (): void {
    Route::get('/_obs/ping', static fn (): string => 'pong');

    $this->get('/_obs/ping')->assertOk()->assertHeader('X-Request-Id');

    $this->get('/_obs/ping', ['X-Request-Id' => 'trace-42'])
        ->assertOk()
        ->assertHeader('X-Request-Id', 'trace-42');
});
