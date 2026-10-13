<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Descripción legible del producto de una línea, tal como llega de la fuente. Sirve para que el
 * preparador y el chofer sepan qué es el bulto sin depender de un catálogo externo.
 */
final readonly class ProductName
{
    private const MAX_LENGTH = 180;

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw ShipmentsException::because('el nombre de producto no puede estar vacío');
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw ShipmentsException::because('el nombre de producto excede '.self::MAX_LENGTH.' caracteres');
        }

        return new self($trimmed);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
