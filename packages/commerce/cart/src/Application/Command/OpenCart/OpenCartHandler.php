<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\OpenCart;

use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Model\Cart;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Abre el carrito, lo persiste y publica sus eventos al outbox (misma transacción que abre el decorador
 * transaccional del CommandBus).
 *
 * @implements CommandHandler<OpenCart>
 */
final readonly class OpenCartHandler implements CommandHandler
{
    public function __construct(
        private CartRepository $carts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof OpenCart);

        $cart = Cart::open(
            $this->carts->nextIdentity(),
            $command->customerId !== null ? Uuid::fromString($command->customerId) : null,
            $command->sessionId,
            $command->currency,
        );

        $this->carts->save($cart);

        $this->recordEvents->record($cart->releaseEvents(), 'cart.cart');
    }
}
