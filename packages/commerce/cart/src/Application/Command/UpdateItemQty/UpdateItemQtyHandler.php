<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\UpdateItemQty;

use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\ValueObject\CartId;
use Commerce\Cart\Domain\ValueObject\Sku;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Fija la cantidad de una línea del carrito (0 = remover) y persiste.
 *
 * @implements CommandHandler<UpdateItemQty>
 */
final readonly class UpdateItemQtyHandler implements CommandHandler
{
    public function __construct(
        private CartRepository $carts,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateItemQty);

        $cart = $this->carts->findById(CartId::fromString($command->cartId));
        if ($cart === null) {
            throw CartException::because('El carrito a actualizar no existe.');
        }

        $cart->updateQty(Sku::of($command->sku), $command->qty);

        $this->carts->save($cart);
    }
}
