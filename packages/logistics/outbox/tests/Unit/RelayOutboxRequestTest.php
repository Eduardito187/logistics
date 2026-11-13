<?php

declare(strict_types=1);

use Logistics\Outbox\Presentation\Http\Request\RelayOutboxRequest;

/**
 * Cobertura del FormRequest del disparo manual del relay: autorización, reglas (límite opcional 1..1000)
 * y el accesor limit(), que cae al valor por defecto cuando no viaja. PHP puro, sin Laravel.
 */
it('autoriza siempre', function (): void {
    $request = new RelayOutboxRequest;

    expect($request->authorize())->toBeTrue();
});

it('acota el tamaño de lote entre 1 y 1000', function (): void {
    $request = new RelayOutboxRequest;

    expect($request->rules())->toBe([
        'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
    ]);
});

it('usa el límite recibido cuando viaja', function (): void {
    $request = RelayOutboxRequest::create('/', 'POST', ['limit' => 50]);

    expect($request->limit(100))->toBe(50);
});

it('cae al valor por defecto cuando el límite no viaja', function (): void {
    $request = RelayOutboxRequest::create('/', 'POST', []);

    expect($request->limit(100))->toBe(100);
});
