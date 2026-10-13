<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

use Logistics\SapConnector\Domain\Exception\SapConnectorException;

/**
 * Referencia de un pedido en SAP (p. ej. el documento de ventas). Identifica al pedido del lado SAP
 * cuando el conector lee pedidos; luego alimenta el id externo del envío propio en el otro contexto.
 */
final readonly class SapOrderRef
{
    private const MAX_LENGTH = 32;

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw SapConnectorException::because('la referencia de pedido SAP no puede estar vacía');
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw SapConnectorException::because('la referencia de pedido SAP excede '.self::MAX_LENGTH.' caracteres');
        }

        return new self($trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
