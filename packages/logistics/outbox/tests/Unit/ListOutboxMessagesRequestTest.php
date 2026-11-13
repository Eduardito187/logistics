<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Presentation\Http\Request\ListOutboxMessagesRequest;

/**
 * Cobertura del FormRequest del listado: autorización, reglas (estado del enum + paginación) y los
 * accesores statusFilter()/page()/perPage() con sus valores por defecto. PHP puro, sin Laravel.
 */
it('autoriza siempre', function (): void {
    $request = new ListOutboxMessagesRequest;

    expect($request->authorize())->toBeTrue();
});

it('valida el estado contra los casos del enum y la paginación', function (): void {
    $request = new ListOutboxMessagesRequest;
    $rules = $request->rules();

    expect($rules)->toHaveKeys(['status', 'page', 'per_page'])
        ->and($rules['page'])->toBe(['nullable', 'integer', 'min:1'])
        ->and($rules['per_page'])->toBe(['nullable', 'integer', 'min:1', 'max:200']);
});

it('expone el filtro de estado recibido', function (): void {
    $request = ListOutboxMessagesRequest::create('/', 'GET', ['status' => OutboxStatus::Failed->value]);

    expect($request->statusFilter())->toBe(OutboxStatus::Failed->value);
});

it('trata un estado vacío como sin filtro', function (): void {
    $request = ListOutboxMessagesRequest::create('/', 'GET', ['status' => '']);

    expect($request->statusFilter())->toBeNull();
});

it('devuelve la paginación por defecto cuando no viaja', function (): void {
    $request = ListOutboxMessagesRequest::create('/', 'GET', []);

    expect($request->page())->toBe(1)
        ->and($request->perPage())->toBe(25);
});

it('lee la paginación recibida', function (): void {
    $request = ListOutboxMessagesRequest::create('/', 'GET', ['page' => '3', 'per_page' => '50']);

    expect($request->page())->toBe(3)
        ->and($request->perPage())->toBe(50);
});
