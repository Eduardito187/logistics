<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Command\CapturePayment;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de capturar (confirmar) el cobro de un pago — típicamente disparada por el webhook de la
 * pasarela.
 *
 * El pago se identifica por su `paymentId` o, si el webhook solo trae la orden, por `orderId`
 * (al menos uno de los dos es obligatorio). `gatewayRef` es la referencia del cobro del proveedor.
 */
final readonly class CapturePayment implements Command
{
    public function __construct(
        public string $gatewayRef,
        public ?string $paymentId = null,
        public ?string $orderId = null,
    ) {}
}
