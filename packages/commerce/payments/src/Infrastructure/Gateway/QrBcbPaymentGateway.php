<?php

declare(strict_types=1);

namespace Commerce\Payments\Infrastructure\Gateway;

use Commerce\Payments\Domain\Contract\PaymentGateway;
use Commerce\Payments\Domain\ValueObject\PaymentIntent;
use Commerce\Payments\Domain\ValueObject\PaymentResult;

/**
 * Pasarela QR Simple del Banco Central de Bolivia — PLACEHOLDER documentado.
 *
 * Hoy delega en el stub: no hay integración real todavía (coherente con "sin servicios de pago", ADR
 * 013/014/015). Deja la estructura lista para enchufar la integración real por env (`PAYMENTS_GATEWAY=qr_bcb`)
 * sin tocar el dominio: cuando exista, este adaptador generará el QR contra el servicio del BCB y la
 * confirmación llegará async por el webhook (→ CapturePayment), manteniendo el pago Pending hasta entonces.
 *
 * El contrato NO cambia: sigue devolviendo un PaymentResult detrás del puerto PaymentGateway.
 */
final class QrBcbPaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly StubPaymentGateway $stub,
    ) {}

    public function charge(PaymentIntent $intent): PaymentResult
    {
        // TODO: integración real con el QR Simple del BCB. Por ahora delega en el stub (dev/tests).
        return $this->stub->charge($intent);
    }
}
