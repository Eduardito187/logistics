<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\ValueObject;

use Commerce\Orders\Domain\Exception\OrdersException;

/**
 * Datos de entrega capturados en el checkout. Se traducen al DTO de ingreso del contexto de envíos
 * (`ReceiveShipment`) en el puente a fulfillment. Contacto opcional (teléfono/email).
 */
final readonly class ShippingAddress
{
    private function __construct(
        public string $customerName,
        public string $addressText,
        public ?string $reference,
        public ?string $phone,
        public ?string $email,
    ) {}

    public static function of(
        string $customerName,
        string $addressText,
        ?string $reference = null,
        ?string $phone = null,
        ?string $email = null,
    ): self {
        $customerName = trim($customerName);
        if ($customerName === '') {
            throw OrdersException::because('El nombre del cliente no puede estar vacío.');
        }
        $addressText = trim($addressText);
        if ($addressText === '') {
            throw OrdersException::because('La dirección de entrega no puede estar vacía.');
        }

        return new self(
            $customerName,
            $addressText,
            self::nullIfEmpty($reference),
            self::nullIfEmpty($phone),
            self::nullIfEmpty($email),
        );
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array{customer_name: string, address_text: string, reference?: ?string, phone?: ?string, email?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['customer_name'],
            $data['address_text'],
            $data['reference'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
        );
    }

    /**
     * @return array{customer_name: string, address_text: string, reference: ?string, phone: ?string, email: ?string}
     */
    public function toArray(): array
    {
        return [
            'customer_name' => $this->customerName,
            'address_text' => $this->addressText,
            'reference' => $this->reference,
            'phone' => $this->phone,
            'email' => $this->email,
        ];
    }
}
