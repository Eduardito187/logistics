<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Snapshot INMUTABLE de la identidad del cliente al momento de capturar la evidencia (anti-fraude).
 *
 * La evidencia queda ligada a ESTE cliente concreto: su id, su número de documento, nombre y teléfono tal
 * como estaban en la entrega. Es un snapshot a propósito (no una FK viva): la evidencia es un hecho
 * histórico y debe conservar contra quién se validó, aunque el cliente cambie después. No se acopla a
 * otros contextos: son primitivos copiados del envío/pedido por quien captura.
 */
final readonly class CustomerIdentitySnapshot
{
    private function __construct(
        public ?string $customerId,
        public ?string $documentId,
        public ?string $name,
        public ?string $phone,
    ) {}

    public static function of(?string $customerId, ?string $documentId, ?string $name, ?string $phone): self
    {
        return new self(
            self::clean($customerId),
            self::clean($documentId),
            self::clean($name),
            self::clean($phone),
        );
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
