<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Query\GetCartBySession;

use Commerce\Cart\Application\ReadModel\CartView;
use Commerce\Cart\Domain\Contract\CartRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el carrito abierto de una sesión, o null si no existe.
 *
 * @implements QueryHandler<GetCartBySession, CartView|null>
 */
final readonly class GetCartBySessionHandler implements QueryHandler
{
    public function __construct(
        private CartRepository $carts,
    ) {}

    public function handle(Query $query): ?CartView
    {
        assert($query instanceof GetCartBySession);

        $cart = $this->carts->findBySession($query->sessionId);

        return $cart !== null ? CartView::fromDomain($cart) : null;
    }
}
