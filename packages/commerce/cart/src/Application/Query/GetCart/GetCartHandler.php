<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Query\GetCart;

use Commerce\Cart\Application\ReadModel\CartView;
use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\ValueObject\CartId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un carrito por su id, o null si no existe.
 *
 * @implements QueryHandler<GetCart, CartView|null>
 */
final readonly class GetCartHandler implements QueryHandler
{
    public function __construct(
        private CartRepository $carts,
    ) {}

    public function handle(Query $query): ?CartView
    {
        assert($query instanceof GetCart);

        $cart = $this->carts->findById(CartId::fromString($query->cartId));

        return $cart !== null ? CartView::fromDomain($cart) : null;
    }
}
