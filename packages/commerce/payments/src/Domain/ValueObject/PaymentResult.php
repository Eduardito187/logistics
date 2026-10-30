<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\ValueObject;

use Commerce\Payments\Domain\Exception\PaymentsException;

/**
 * Resultado que devuelve el puerto `PaymentGateway::charge()`.
 *
 * Es un value object inmutable: o bien aprobado con una referencia del proveedor (`gatewayRef`), o bien
 * rechazado con un motivo legible (`failureReason`). La referencia es el único dato del proveedor que
 * se persiste (PCI: nunca datos de tarjeta).
 */
final readonly class PaymentResult
{
    private function __construct(
        public bool $approved,
        public ?string $gatewayRef,
        public ?string $failureReason,
    ) {}

    public static function approved(string $gatewayRef): self
    {
        $gatewayRef = trim($gatewayRef);
        if ($gatewayRef === '') {
            throw PaymentsException::because('Un resultado aprobado exige una referencia del proveedor.');
        }

        return new self(true, $gatewayRef, null);
    }

    public static function declined(string $failureReason): self
    {
        $failureReason = trim($failureReason);
        if ($failureReason === '') {
            $failureReason = 'declined';
        }

        return new self(false, null, $failureReason);
    }

    /**
     * @return array{approved: bool, gateway_ref: ?string, failure_reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'gateway_ref' => $this->gatewayRef,
            'failure_reason' => $this->failureReason,
        ];
    }
}
