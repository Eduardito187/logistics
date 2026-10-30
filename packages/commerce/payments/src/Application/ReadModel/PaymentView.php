<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\ReadModel;

use Commerce\Payments\Domain\Model\Payment;

/**
 * Proyección de lectura de un pago para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class PaymentView
{
    /**
     * @param array{cents: int, currency: string} $amount
     */
    private function __construct(
        public string $id,
        public string $orderId,
        public array $amount,
        public string $method,
        public string $status,
        public ?string $gatewayRef,
    ) {}

    public static function fromDomain(Payment $payment): self
    {
        return new self(
            $payment->id()->toString(),
            $payment->orderId()->value,
            $payment->amount()->toArray(),
            $payment->method()->value,
            $payment->status()->value,
            $payment->gatewayRef(),
        );
    }

    /**
     * @return array{id: string, order_id: string, amount: array{cents: int, currency: string}, method: string, status: string, gateway_ref: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'method' => $this->method,
            'status' => $this->status,
            'gateway_ref' => $this->gatewayRef,
        ];
    }
}
