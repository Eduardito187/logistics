<?php

declare(strict_types=1);

use Commerce\Cart\Application\Command\AddItem\AddItem;
use Commerce\Cart\Application\Command\OpenCart\OpenCart;
use Commerce\Cart\Application\Query\GetCart\GetCart;
use Commerce\Cart\Application\Query\GetCartBySession\GetCartBySession;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Circuito del carrito contra PostgreSQL real (logistics_test, RefreshDatabase): abrir un carrito,
 * agregar dos líneas y leerlo por las queries con el total correcto. Atraviesa
 * CommandBus → handler → puerto de inventory (stub) → repositorio → mapper → persistencia → QueryBus
 * → ReadModel.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('abre un carrito, agrega dos líneas y consulta el CartView con el total correcto', function (): void {
    $this->commands->dispatch(new OpenCart('sess-xyz'));

    $bySession = $this->queries->ask(new GetCartBySession('sess-xyz'));
    expect($bySession)->not->toBeNull()
        ->and($bySession->status)->toBe('open')
        ->and($bySession->currency)->toBe('BOB');
    $cartId = $bySession->id;

    $this->commands->dispatch(new AddItem($cartId, 'SKU-TV-55', 'Televisor LED 55"', 150000, 1));
    $this->commands->dispatch(new AddItem($cartId, 'SKU-SB-1', 'Barra de sonido', 50000, 2));

    $view = $this->queries->ask(new GetCart($cartId));
    expect($view)->not->toBeNull()
        ->and($view->lines)->toHaveCount(2)
        ->and($view->totalCents)->toBe(150000 + 50000 * 2);

    $viewArray = $view->toArray();
    expect($viewArray['total_cents'])->toBe(250000)
        ->and($viewArray['lines'][0]['subtotal_cents'])->toBe(150000);
});

it('sumar el mismo SKU acumula la cantidad en una sola línea', function (): void {
    $this->commands->dispatch(new OpenCart('sess-dup'));
    $cartId = $this->queries->ask(new GetCartBySession('sess-dup'))->id;

    $this->commands->dispatch(new AddItem($cartId, 'SKU-A', 'Producto A', 1000, 1));
    $this->commands->dispatch(new AddItem($cartId, 'SKU-A', 'Producto A', 1000, 2));

    $view = $this->queries->ask(new GetCart($cartId));
    expect($view->lines)->toHaveCount(1)
        ->and($view->lines[0]['qty'])->toBe(3)
        ->and($view->totalCents)->toBe(3000);
});
