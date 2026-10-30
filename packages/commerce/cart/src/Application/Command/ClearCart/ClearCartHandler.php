<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\ClearCart;

use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\ValueObject\CartId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Vacía el carrito y persiste.
 *
 * @implements CommandHandler<ClearCart>
 */
final readonly class ClearCartHandler implements CommandHandler
{
    public function __construct(
        private CartRepository $carts,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ClearCart);

        $cart = $this->carts->findById(CartId::fromString($command->cartId));
        if ($cart === null) {
            throw CartException::because('El carrito a vaciar no existe.');
        }

        $cart->clear();

        $this->carts->save($cart);
    }
}
