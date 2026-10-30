<?php

declare(strict_types=1);

namespace Commerce\Cart\Infrastructure\Dispatch;

use Commerce\Cart\Application\Command\MarkCartConverted\MarkCartConverted;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puente orders → cart por outbox: cuando se crea una orden (`orders.order_placed`), marca el carrito
 * de origen como convertido. Consume el evento por su NOMBRE y PAYLOAD, sin `use` de clases de `orders`
 * (así cada contexto queda extraíble a microservicio). Idempotente: `MarkCartConverted` lo es en el
 * agregado, y el outbox reentrega at-least-once.
 *
 * REQUIERE que el payload de `orders.order_placed` lleve `cart_id`. Hoy el evento NO lo propaga (el
 * agregado Order no conoce el carrito); mientras no se propague, este converter no encuentra el cart_id
 * y no hace nada (degrada seguro). Ver el reporte de EC-F0-05/06 para el cambio pendiente en `orders`.
 */
final readonly class OrderPlacedCartConverter implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_placed') {
            return;
        }

        $payload = $message->payload();
        $cartId = $payload['cart_id'] ?? null;

        if (!is_string($cartId) || $cartId === '') {
            return;
        }

        $this->commandBus->dispatch(new MarkCartConverted($cartId));
    }
}
