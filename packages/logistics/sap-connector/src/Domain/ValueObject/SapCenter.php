<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

use Logistics\SapConnector\Domain\Exception\SapConnectorException;

/**
 * Centro SAP (Werk): la unidad organizativa de planificación/stock. Parte de la coordenada de un
 * traspaso (material + centro + almacén). Solo se valida su forma; SAP es la autoridad del código.
 */
final readonly class SapCenter
{
    private const MAX_LENGTH = 8;

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw SapConnectorException::because('el centro SAP no puede estar vacío');
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw SapConnectorException::because('el centro SAP excede '.self::MAX_LENGTH.' caracteres');
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
