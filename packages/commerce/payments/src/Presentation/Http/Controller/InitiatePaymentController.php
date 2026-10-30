<?php

declare(strict_types=1);

namespace Commerce\Payments\Presentation\Http\Controller;

use Commerce\Payments\Application\Command\InitiatePayment\InitiatePayment;
use Commerce\Payments\Application\Query\GetPaymentByOrder\GetPaymentByOrder;
use Commerce\Payments\Application\ReadModel\PaymentView;
use Commerce\Payments\Presentation\Http\ApiController;
use Commerce\Payments\Presentation\Http\Request\InitiatePaymentRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Inicia el cobro de una orden (puerta `channels`): despacha InitiatePayment y, como la escritura es
 * síncrona-transaccional, relee el pago por la orden para devolver su `gateway_ref` al storefront (que
 * lo usa para redirigir/mostrar el checkout de la pasarela). El estado final lo confirma el webhook.
 */
final class InitiatePaymentController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(InitiatePaymentRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $orderId = $request->string('order_id')->toString();
            $currency = $request->string('currency')->toString();

            $this->commands->dispatch(new InitiatePayment(
                orderId: $orderId,
                amountCents: $request->integer('amount_cents'),
                currency: $currency !== '' ? $currency : 'BOB',
                method: $request->string('method')->toString(),
            ));

            /** @var PaymentView|null $payment */
            $payment = $this->queries->ask(new GetPaymentByOrder(orderId: $orderId));
            if ($payment === null) {
                return $this->error('internal_error', 'The payment could not be read back after initiation.', 500);
            }

            return $this->data($payment->toArray(), 201);
        });
    }
}
