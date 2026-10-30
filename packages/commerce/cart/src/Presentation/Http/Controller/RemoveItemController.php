<?php

declare(strict_types=1);

namespace Commerce\Cart\Presentation\Http\Controller;

use Commerce\Cart\Application\Command\RemoveItem\RemoveItem;
use Commerce\Cart\Application\Query\GetCart\GetCart;
use Commerce\Cart\Application\ReadModel\CartView;
use Commerce\Cart\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: remueve una línea (por SKU) del carrito (comando RemoveItem). No lleva cuerpo. Tras la
 * escritura síncrona se devuelve el carrito actualizado (200); 404 si el carrito no existe.
 */
final class RemoveItemController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $cart, string $sku): JsonResponse
    {
        return $this->guard(function () use ($cart, $sku): JsonResponse {
            $this->commands->dispatch(new RemoveItem(
                cartId: $cart,
                sku: $sku,
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
