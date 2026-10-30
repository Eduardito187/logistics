<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\RemoveItem;

use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\ValueObject\CartId;
use Commerce\Cart\Domain\ValueObject\Sku;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Remueve una línea del carrito y persiste.
 *
 * @implements CommandHandler<RemoveItem>
 */
final readonly class RemoveItemHandler implements CommandHandler
{
    public function __construct(
        private CartRepository $carts,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RemoveItem);

        $cart = $this->carts->findById(CartId::fromString($command->cartId));
        if ($cart === null) {
            throw CartException::because('El carrito del que se remueve el ítem no existe.');
        }

        $cart->removeItem(Sku::of($command->sku));

        $this->carts->save($cart);
    }
}
