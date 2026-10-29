<?php

declare(strict_types=1);

namespace Commerce\Payments\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Payments\Domain\Model\Payment;
use Commerce\Payments\Domain\ValueObject\Money;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Commerce\Payments\Domain\ValueObject\PaymentMethod;
use Commerce\Payments\Domain\ValueObject\PaymentStatus;
use Commerce\Payments\Infrastructure\Persistence\Eloquent\Model\PaymentRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Traduce entre el agregado Payment (dominio) y su fila Eloquent. Único punto que conoce ambos lados.
 */
final class PaymentMapper
{
    public function toDomain(PaymentRecord $record): Payment
    {
        return Payment::reconstitute(
            PaymentId::fromString($record->id),
            Uuid::fromString($record->order_id),
            Money::of($record->amount_cents, $record->currency),
            PaymentMethod::from($record->method),
            PaymentStatus::from($record->status),
            $record->gateway_ref,
        );
    }

    /**
     * Fila de la tabla `commerce_payments`.
     *
     * @return array{id: string, order_id: string, amount_cents: int, currency: string, method: string, status: string, gateway_ref: ?string}
     */
    public function toRow(Payment $payment): array
    {
        $amount = $payment->amount();

        return [
            'id' => $payment->id()->toString(),
            'order_id' => $payment->orderId()->value,
            'amount_cents' => $amount->cents,
            'currency' => $amount->currency,
            'method' => $payment->method()->value,
            'status' => $payment->status()->value,
            'gateway_ref' => $payment->gatewayRef(),
        ];
    }
}
