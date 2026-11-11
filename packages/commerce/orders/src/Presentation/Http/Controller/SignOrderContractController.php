<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Controller;

use Commerce\Orders\Application\Command\SignOrderContract\SignOrderContract;
use Commerce\Orders\Application\Query\GetOrder\GetOrder;
use Commerce\Orders\Presentation\Http\ApiController;
use Commerce\Orders\Presentation\Http\Request\CreditSignalRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Registra la FIRMA DEL CONTRATO de crédito de una orden (puerta `integrations`, origen POS/crédito).
 *
 * 404 si la orden no existe; si existe, despacha SignOrderContract (escritura asíncrona por outbox → 202
 * de acuse). Las violaciones de regla de dominio (orden no a crédito, cancelada) las traduce guard() a 422.
 */
final class SignOrderContractController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(CreditSignalRequest $request, string $order): JsonResponse
    {
        return $this->guard(function () use ($request, $order): JsonResponse {
            if ($this->queries->ask(new GetOrder(orderId: $order)) === null) {
                return $this->error('order_not_found', 'No order found for the given identifier.', 404);
            }

            $this->commands->dispatch(new SignOrderContract(
                orderId: $order,
                reference: $request->reference(),
            ));

            return $this->accepted([
                'order_id' => $order,
            ]);
        });
    }
}
