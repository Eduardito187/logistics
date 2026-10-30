<?php

declare(strict_types=1);

namespace Commerce\Payments\Presentation\Http\Controller;

use Commerce\Payments\Application\Command\CapturePayment\CapturePayment;
use Commerce\Payments\Presentation\Http\ApiController;
use Commerce\Payments\Presentation\Http\Request\PaymentWebhookRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Recibe la confirmación de cobro de la pasarela y despacha CapturePayment al bus CQRS.
 *
 * La captura es idempotente (el agregado no re-graba si el pago ya está capturado), así que un webhook
 * reentregado no duplica el hecho `payments.captured` ni la confirmación de la orden aguas abajo. El
 * comando no devuelve valor: la respuesta es un 202 de acuse.
 */
final class PaymentWebhookController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(PaymentWebhookRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $paymentId = $request->string('payment_id')->toString();
            $orderId = $request->string('order_id')->toString();

            $this->commands->dispatch(new CapturePayment(
                gatewayRef: $request->string('gateway_ref')->toString(),
                paymentId: $paymentId !== '' ? $paymentId : null,
                orderId: $orderId !== '' ? $orderId : null,
            ));

            return $this->accepted([
                'payment_id' => $paymentId !== '' ? $paymentId : null,
                'order_id' => $orderId !== '' ? $orderId : null,
            ]);
        });
    }
}
