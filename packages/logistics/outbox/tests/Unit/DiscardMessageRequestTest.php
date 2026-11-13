<?php

declare(strict_types=1);

use Logistics\Outbox\Presentation\Http\Request\DiscardMessageRequest;

/**
 * Cobertura del FormRequest del descarte manual: autorización, reglas (motivo requerido 3..500) y el
 * accesor reason(). PHP puro, sin Laravel: se construye el request con sus datos y se leen directamente.
 */
it('autoriza siempre (la autorización por rol la aplica el middleware de puerta)', function (): void {
    $request = new DiscardMessageRequest;

    expect($request->authorize())->toBeTrue();
});

it('exige un motivo entre 3 y 500 caracteres', function (): void {
    $request = new DiscardMessageRequest;

    expect($request->rules())->toBe([
        'reason' => ['required', 'string', 'min:3', 'max:500'],
    ]);
});

it('expone el motivo recibido como string', function (): void {
    $request = DiscardMessageRequest::create('/', 'POST', ['reason' => 'Evento huérfano']);

    expect($request->reason())->toBe('Evento huérfano');
});
