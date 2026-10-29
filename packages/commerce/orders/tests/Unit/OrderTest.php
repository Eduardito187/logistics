<?php

declare(strict_types=1);

use Commerce\Orders\Domain\Event\OrderCancelled;
use Commerce\Orders\Domain\Event\OrderFulfillmentStarted;
use Commerce\Orders\Domain\Event\OrderPaid;
use Commerce\Orders\Domain\Event\OrderPlaced;
use Commerce\Orders\Domain\Exception\OrdersException;
use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Commerce\Orders\Domain\ValueObject\OrderLine;
use Commerce\Orders\Domain\ValueObject\OrderStatus;
use Commerce\Orders\Domain\ValueObject\ShippingAddress;

function ordersPlaceOrder(): Order
{
    return Order::place(
        OrderId::generate(),
        null,
        ShippingAddress::of('Ana Pérez', 'Av. Siempre Viva 123, Santa Cruz'),
        [
            OrderLine::of('SKU-TV-55', 'Televisor', 450000, 1),
            OrderLine::of('SKU-WM-12', 'Lavadora', 300000, 2),
        ],
    );
}

it('place crea la orden pendiente, calcula el total y graba OrderPlaced', function (): void {
    $order = ordersPlaceOrder();

    expect($order->status())->toBe(OrderStatus::Pending)
        ->and($order->totalCents())->toBe(450000 + 300000 * 2);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderPlaced::class);
    expect($events[0]->payload()['total_cents'])->toBe(1050000);
});

it('no permite una orden sin líneas', function (): void {
    expect(fn () => Order::place(OrderId::generate(), null, ShippingAddress::of('X', 'Y'), []))
        ->toThrow(OrdersException::class);
});

it('markPaid pasa a Paid y graba orders.order_paid; es idempotente', function (): void {
    $order = ordersPlaceOrder();
    $order->releaseEvents();

    $order->markPaid('STUB-REF-1');
    expect($order->status())->toBe(OrderStatus::Paid);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderPaid::class)
        ->and($events[0]->eventName())->toBe('orders.order_paid');

    $order->markPaid('STUB-REF-1');
    expect($order->releaseEvents())->toHaveCount(0);
});

it('markFulfilling exige orden pagada y es idempotente', function (): void {
    $order = ordersPlaceOrder();
    $order->releaseEvents();

    expect(fn () => $order->markFulfilling())->toThrow(OrdersException::class);

    $order->markPaid('REF');
    $order->releaseEvents();

    $order->markFulfilling();
    expect($order->status())->toBe(OrderStatus::Fulfilling)
        ->and($order->shipmentRequested())->toBeTrue();
    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OrderFulfillmentStarted::class);

    $order->markFulfilling();
    expect($order->releaseEvents())->toHaveCount(0);
});

it('no se puede pagar una orden cancelada; cancelar solo si está pendiente', function (): void {
    $order = ordersPlaceOrder();
    $order->releaseEvents();

    $order->cancel();
    expect($order->status())->toBe(OrderStatus::Cancelled);
    expect($order->releaseEvents()[0])->toBeInstanceOf(OrderCancelled::class);

    expect(fn () => $order->markPaid('X'))->toThrow(OrdersException::class);
});
