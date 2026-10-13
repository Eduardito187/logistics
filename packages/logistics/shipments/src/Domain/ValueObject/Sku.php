<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Código de producto tal como lo manda la fuente de origen. NO es necesariamente un material SAP:
 * puede venir de Magento, de una tienda o de un Excel. El contexto no lo valida contra ningún catálogo.
 */
final readonly class Sku
{
    private const MAX_LENGTH = 64;

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw ShipmentsException::because('el SKU no puede estar vacío');
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw ShipmentsException::because('el SKU excede '.self::MAX_LENGTH.' caracteres');
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
