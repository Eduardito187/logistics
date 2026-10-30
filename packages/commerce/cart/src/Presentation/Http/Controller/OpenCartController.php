<?php

declare(strict_types=1);

namespace Commerce\Cart\Presentation\Http\Controller;

use Commerce\Cart\Application\Command\OpenCart\OpenCart;
use Commerce\Cart\Application\Query\GetCartBySession\GetCartBySession;
use Commerce\Cart\Application\ReadModel\CartView;
use Commerce\Cart\Presentation\Http\ApiController;
use Commerce\Cart\Presentation\Http\Request\OpenCartRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: abre un carrito para una sesión del storefront (cliente opcional = invitado).
 *
 * La escritura es síncrona (el decorador transaccional del CommandBus persiste en la misma transacción),
 * así que tras despachar OpenCart se lee el carrito recién abierto por su sesión y se devuelve con 201.
 */
final class OpenCartController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(OpenCartRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $sessionId = $request->string('session_id')->toString();
            $customerId = $request->string('customer_id')->toString();
            $currency = $request->string('currency')->toString();

            $this->commands->dispatch(new OpenCart(
                sessionId: $sessionId,
                customerId: $customerId !== '' ? $customerId : null,
                currency: $currency !== '' ? $currency : 'BOB',
            ));

            /** @var CartView|null $cart */
            $cart = $this->queries->ask(new GetCartBySession($sessionId));

            if ($cart === null) {
                return $this->error('cart_not_found', 'The cart could not be opened.', 500);
            }

            return $this->data($cart->toArray(), 201);
        });
    }
}
