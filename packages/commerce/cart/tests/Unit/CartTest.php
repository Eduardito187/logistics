<?php

declare(strict_types=1);

use Commerce\Cart\Domain\Event\CartConverted;
use Commerce\Cart\Domain\Event\CartItemAdded;
use Commerce\Cart\Domain\Event\CartMerged;
use Commerce\Cart\Domain\Event\CartOpened;
use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\Model\Cart;
use Commerce\Cart\Domain\ValueObject\CartId;
use Commerce\Cart\Domain\ValueObject\CartLine;
use Commerce\Cart\Domain\ValueObject\CartStatus;
use Commerce\Cart\Domain\ValueObject\Sku;
use Logistics\Shared\Domain\ValueObject\Uuid;

function cartOpenGuest(): Cart
{
    return Cart::open(CartId::generate(), null, 'sess-abc');
}

function cartLine(string $sku, int $unitPriceCents = 1000, int $qty = 1): CartLine
{
    return CartLine::of(Sku::of($sku), "Producto {$sku}", $unitPriceCents, $qty);
}

it('open crea un carrito de invitado abierto en BOB y graba CartOpened', function (): void {
    $cart = cartOpenGuest();

    expect($cart->status())->toBe(CartStatus::Open)
        ->and($cart->currency())->toBe('BOB')
        ->and($cart->customerId())->toBeNull()
        ->and($cart->sessionId())->toBe('sess-abc');

    $events = $cart->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CartOpened::class);
});

it('addItem con el mismo SKU suma la cantidad en una sola línea', function (): void {
    $cart = cartOpenGuest();
    $cart->releaseEvents();

    $cart->addItem(cartLine('SKU-A', 1000, 1));
    $cart->addItem(cartLine('SKU-A', 1000, 2));

    expect($cart->lines())->toHaveCount(1)
        ->and($cart->lines()[0]->qty)->toBe(3)
        ->and($cart->totalCents())->toBe(3000);

    $events = $cart->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(CartItemAdded::class);
});

it('totalCents suma los subtotales de todas las líneas', function (): void {
    $cart = cartOpenGuest();
    $cart->addItem(cartLine('SKU-A', 1500, 2)); // 3000
    $cart->addItem(cartLine('SKU-B', 500, 3));  // 1500
    $cart->releaseEvents();

    expect($cart->totalCents())->toBe(4500)
        ->and($cart->lines())->toHaveCount(2);
});

it('updateQty 0 remueve la línea y reindexa', function (): void {
    $cart = cartOpenGuest();
    $cart->addItem(cartLine('SKU-A'));
    $cart->addItem(cartLine('SKU-B'));
    $cart->releaseEvents();

    $cart->updateQty(Sku::of('SKU-A'), 0);

    expect($cart->lines())->toHaveCount(1)
        ->and(array_keys($cart->lines()))->toBe([0])
        ->and($cart->lines()[0]->sku->value)->toBe('SKU-B');
});

it('updateQty fija la cantidad de un SKU existente', function (): void {
    $cart = cartOpenGuest();
    $cart->addItem(cartLine('SKU-A', 1000, 1));
    $cart->releaseEvents();

    $cart->updateQty(Sku::of('SKU-A'), 5);

    expect($cart->lines()[0]->qty)->toBe(5)
        ->and($cart->totalCents())->toBe(5000);
});

it('updateQty sobre un SKU inexistente falla', function (): void {
    $cart = cartOpenGuest();
    $cart->releaseEvents();

    expect(fn () => $cart->updateQty(Sku::of('SKU-X'), 3))->toThrow(CartException::class);
});

it('mergeFrom fusiona las líneas del invitado sumando por SKU y graba CartMerged', function (): void {
    $target = cartOpenGuest();
    $target->addItem(cartLine('SKU-A', 1000, 1));
    $target->releaseEvents();

    $guest = Cart::open(CartId::generate(), null, 'sess-guest');
    $guest->addItem(cartLine('SKU-A', 1000, 2));
    $guest->addItem(cartLine('SKU-B', 500, 1));
    $guest->releaseEvents();

    $target->mergeFrom($guest);

    expect($target->lines())->toHaveCount(2)
        ->and($target->totalCents())->toBe(3000 + 500);

    $skuA = array_values(array_filter($target->lines(), fn (CartLine $l): bool => $l->sku->value === 'SKU-A'))[0];
    expect($skuA->qty)->toBe(3);

    $events = $target->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CartMerged::class);
});

it('markConverted convierte el carrito y graba CartConverted una sola vez (idempotente)', function (): void {
    $cart = Cart::open(CartId::generate(), Uuid::generate(), 'sess-abc');
    $cart->addItem(cartLine('SKU-A', 2000, 2));
    $cart->releaseEvents();

    $cart->markConverted();
    expect($cart->status())->toBe(CartStatus::Converted);

    $events = $cart->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CartConverted::class);
    expect($events[0]->payload()['total_cents'])->toBe(4000)
        ->and($events[0]->payload()['lines'])->toHaveCount(1);

    // Idempotente: convertir de nuevo no graba otro evento.
    $cart->markConverted();
    expect($cart->releaseEvents())->toHaveCount(0);
});

it('addItem sobre un carrito que no está Open falla', function (): void {
    $cart = cartOpenGuest();
    $cart->markConverted();
    $cart->releaseEvents();

    expect(fn () => $cart->addItem(cartLine('SKU-A')))->toThrow(CartException::class);
});
