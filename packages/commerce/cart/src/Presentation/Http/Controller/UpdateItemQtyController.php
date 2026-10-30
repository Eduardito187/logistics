<?php

declare(strict_types=1);

namespace Commerce\Cart\Presentation\Http\Controller;

use Commerce\Cart\Application\Command\UpdateItemQty\UpdateItemQty;
use Commerce\Cart\Application\Query\GetCart\GetCart;
use Commerce\Cart\Application\ReadModel\CartView;
use Commerce\Cart\Presentation\Http\ApiController;
use Commerce\Cart\Presentation\Http\Request\UpdateItemQtyRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: fija la cantidad de un SKU del carrito (comando UpdateItemQty). Cantidad 0 remueve la
 * línea. Tras la escritura síncrona se devuelve el carrito actualizado (200); 404 si el carrito no existe.
 */
final class UpdateItemQtyController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(UpdateItemQtyRequest $request, string $cart, string $sku): JsonResponse
    {
        return $this->guard(function () use ($request, $cart, $sku): JsonResponse {
            $this->commands->dispatch(new UpdateItemQty(
                cartId: $cart,
                sku: $sku,
                qty: $request->integer('quantity'),
            ));

            /** @var CartView|null $view */
            $view = $this->queries->ask(new GetCart($cart));

            if ($view === null) {
                return $this->error('cart_not_found', "Cart {$cart} not found.", 404);
            }

            return $this->data($view->toArray());
        });
    }
}
