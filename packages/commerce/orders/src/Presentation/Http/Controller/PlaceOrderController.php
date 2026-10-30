<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Controller;

use Commerce\Orders\Presentation\Http\ApiController;
use Commerce\Orders\Presentation\Http\Request\PlaceOrderRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Checkout del storefront (puerta `channels`): despacha PlaceOrder al bus CQRS.
 *
 * El comando genera internamente el id de la orden (nextIdentity) y el bus no devuelve valor: la
 * escritura va envuelta en transacción + outbox, y el pago/fulfillment ocurren después de forma
 * asíncrona (eventos `orders.order_placed`). Por eso la respuesta es un 202 de acuse, sin exponer el
 * id generado. Las violaciones de regla de dominio (líneas vacías, datos de entrega inválidos) las
 * traduce guard() a 422.
 */
final class PlaceOrderController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(PlaceOrderRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $command = $request->toCommand();

            $this->commands->dispatch($command);

            return $this->accepted([
                'customer_name' => $command->customerName,
                'currency' => $command->currency,
            ]);
        });
    }
}
