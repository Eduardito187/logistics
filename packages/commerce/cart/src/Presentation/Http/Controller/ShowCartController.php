<?php

declare(strict_types=1);

namespace Commerce\Cart\Presentation\Http\Controller;

use Commerce\Cart\Application\Query\GetCart\GetCart;
use Commerce\Cart\Application\ReadModel\CartView;
use Commerce\Cart\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: detalle de un carrito por su id (consulta GetCart). Devuelve 404 si no existe.
 */
final class ShowCartController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $cart): JsonResponse
    {
        return $this->guard(function () use ($cart): JsonResponse {
            /** @var CartView|null $view */
            $view = $this->queries->ask(new GetCart($cart));

            if ($view === null) {
                return $this->error('cart_not_found', "Cart {$cart} not found.", 404);
            }

            return $this->data($view->toArray());
        });
    }
}
