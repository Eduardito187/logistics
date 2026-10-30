<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\MarkCartConverted;

use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\ValueObject\CartId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Marca el carrito como convertido, persiste y publica `cart.converted` al outbox (el puente a `orders`
 * es idempotente por order_id aguas abajo). La operación de dominio es idempotente.
 *
 * @implements CommandHandler<MarkCartConverted>
 */
final readonly class MarkCartConvertedHandler implements CommandHandler
{
    public function __construct(
        private CartRepository $carts,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof MarkCartConverted);

        $cart = $this->carts->findById(CartId::fromString($command->cartId));
        if ($cart === null) {
            throw CartException::because('El carrito a convertir no existe.');
        }

        $cart->markConverted();

        $this->carts->save($cart);

        $this->recordEvents->record($cart->releaseEvents(), 'cart.cart');
    }
}
