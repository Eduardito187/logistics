<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Controller;

use Commerce\Orders\Presentation\Http\ApiController;
use Commerce\Orders\Presentation\Http\Request\CheckoutRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Checkout orquestado del storefront (puerta `channels`): toma un carrito existente y despacha
 * PlaceOrderFromCart al bus CQRS.
 *
 * El handler lee la instantánea del carrito y crea la orden (que genera internamente su id). La
 * conversión del carrito ocurre después, desacoplada por outbox. Por eso la respuesta es un 202 de
 * acuse, sin exponer ids generados. Las violaciones de regla de dominio (carrito inexistente o vacío,
 * datos de entrega inválidos) las traduce guard() a 422.
 */
final class CheckoutController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(CheckoutRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $command = $request->toCommand();

            $this->commands->dispatch($command);

            return $this->accepted([
                'cart_id' => $command->cartId,
            ]);
        });
    }
}
