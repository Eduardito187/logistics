<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

use Logistics\SapConnector\Domain\Exception\SapConnectorException;

/**
 * Almacén SAP (Lagerort) dentro de un centro: el destino físico fino del stock. Completa la
 * coordenada de un traspaso (material + centro + almacén). Solo se valida su forma.
 */
final readonly class SapWarehouse
{
    private const MAX_LENGTH = 8;

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw SapConnectorException::because('el almacén SAP no puede estar vacío');
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw SapConnectorException::because('el almacén SAP excede '.self::MAX_LENGTH.' caracteres');
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
