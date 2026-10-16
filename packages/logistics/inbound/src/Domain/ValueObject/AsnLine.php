<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\ValueObject;

use Logistics\Inbound\Domain\Exception\InboundException;

/**
 * Línea de un ASN: el SKU del seller y la cantidad esperada de esa pieza en la recepción. Value object
 * inmutable. El SKU es el código propio del seller (ver contexto sellers); su mapeo a material SAP lo
 * resuelve el sap-connector aguas abajo, no inbound.
 */
final readonly class AsnLine
{
    private function __construct(
        public string $sku,
        public int $expectedQuantity,
    ) {}

    public static function of(string $sku, int $expectedQuantity): self
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw InboundException::because('El SKU de la línea del ASN no puede estar vacío.');
        }
        if ($expectedQuantity <= 0) {
            throw InboundException::because("La cantidad esperada debe ser positiva: {$expectedQuantity}.");
        }

        return new self($sku, $expectedQuantity);
    }

    public function equals(self $other): bool
    {
        return $this->sku === $other->sku && $this->expectedQuantity === $other->expectedQuantity;
    }

    /**
     * @return array{sku: string, expected_quantity: int}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'expected_quantity' => $this->expectedQuantity,
        ];
    }
}
