<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Intención de cobro que se entrega al puerto `PaymentGateway::charge()`.
 *
 * Es un value object inmutable con lo mínimo que una pasarela necesita para intentar el cobro: a qué
 * pago y orden corresponde, cuánto, con qué método y, opcionalmente, a dónde volver tras el flujo del
 * proveedor (QR/redirección). No lleva datos de tarjeta: la tokenización/captura es del proveedor (PCI).
 */
final readonly class PaymentIntent
{
    private function __construct(
        public PaymentId $paymentId,
        public Uuid $orderId,
        public Money $amount,
        public PaymentMethod $method,
        public ?string $returnUrl,
    ) {}

    public static function of(
        PaymentId $paymentId,
        Uuid $orderId,
        Money $amount,
        PaymentMethod $method,
        ?string $returnUrl = null,
    ): self {
        $returnUrl = $returnUrl !== null ? trim($returnUrl) : null;
        if ($returnUrl === '') {
            $returnUrl = null;
        }

        return new self($paymentId, $orderId, $amount, $method, $returnUrl);
    }
}
