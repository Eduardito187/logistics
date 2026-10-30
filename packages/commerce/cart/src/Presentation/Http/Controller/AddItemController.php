<?php

declare(strict_types=1);

namespace Commerce\Cart\Presentation\Http\Controller;

use Commerce\Cart\Application\Command\AddItem\AddItem;
use Commerce\Cart\Application\Query\GetCart\GetCart;
use Commerce\Cart\Application\ReadModel\CartView;
use Commerce\Cart\Presentation\Http\ApiController;
use Commerce\Cart\Presentation\Http\Request\AddItemRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: agrega una línea al carrito (comando AddItem). El precio unitario viaja resuelto por
 * storefront; el handler verifica disponibilidad (ATP). Tras la escritura síncrona se devuelve el
 * carrito actualizado (200); 404 si el carrito no existe.
 */
final class AddItemController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(AddItemRequest $request, string $cart): JsonResponse
    {
        return $this->guard(function () use ($request, $cart): JsonResponse {
            $this->commands->dispatch(new AddItem(
                cartId: $cart,
                sku: $request->string('sku')->toString(),
                productName: $request->string('product_name')->toString(),
                unitPriceCents: $request->integer('unit_price_cents'),
                qty: $request->integer('quantity', 1),
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
