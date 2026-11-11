<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Controller;

use Commerce\Orders\Application\Command\RecordInitialPayment\RecordInitialPayment;
use Commerce\Orders\Application\Query\GetOrder\GetOrder;
use Commerce\Orders\Presentation\Http\ApiController;
use Commerce\Orders\Presentation\Http\Request\CreditSignalRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Registra el PAGO DE LA CUOTA INICIAL de una orden de crédito con inicial (puerta `integrations`,
 * origen POS/crédito).
 *
 * 404 si la orden no existe; si existe, despacha RecordInitialPayment (escritura asíncrona por outbox →
 * 202). Las violaciones de regla (orden no a crédito, sin inicial, cancelada) las traduce guard() a 422.
 */
final class RecordInitialPaymentController extends ApiController
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

            $this->commands->dispatch(new RecordInitialPayment(
                orderId: $order,
                reference: $request->reference(),
            ));

            return $this->accepted([
                'order_id' => $order,
            ]);
        });
    }
}
