<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Datos básicos del destinatario del pedido: nombre (obligatorio) y datos de contacto opcionales
 * (documento de identidad CI/NIT, teléfono y correo). Es un value object inmutable: el nombre se
 * valida aquí (antes vivía en Shipment) y los campos de contacto se normalizan (vacío → null).
 *
 * El contacto es opcional porque las fuentes históricas o mínimas (Excel, ingesta vieja) no siempre
 * lo traen; cuando llega, lo consumen el notificador y el panel para mostrar la ficha del cliente.
 */
final readonly class Customer
{
    private const MAX_NAME_LENGTH = 180;

    private const MAX_DOCUMENT_LENGTH = 40;

    private const MAX_PHONE_LENGTH = 40;

    private const MAX_EMAIL_LENGTH = 180;

    private function __construct(
        public string $name,
        public ?string $documentId,
        public ?string $phone,
        public ?string $email,
    ) {}

    public static function of(
        string $name,
        ?string $documentId = null,
        ?string $phone = null,
        ?string $email = null,
    ): self {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw ShipmentsException::because('el nombre del cliente no puede estar vacío');
        }
        if (mb_strlen($trimmedName) > self::MAX_NAME_LENGTH) {
            throw ShipmentsException::because('el nombre del cliente excede '.self::MAX_NAME_LENGTH.' caracteres');
        }

        return new self(
            $trimmedName,
            self::normalize($documentId, self::MAX_DOCUMENT_LENGTH, 'el documento de identidad'),
            self::normalize($phone, self::MAX_PHONE_LENGTH, 'el teléfono'),
            self::normalize($email, self::MAX_EMAIL_LENGTH, 'el correo'),
        );
    }

    /**
     * Normaliza un dato de contacto opcional: recorta espacios, trata el vacío como ausente (null) y
     * valida el largo máximo. Devuelve null cuando no hay dato.
     */
    private static function normalize(?string $value, int $maxLength, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            throw ShipmentsException::because($label.' excede '.$maxLength.' caracteres');
        }

        return $trimmed;
    }
}
