<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Snapshot inmutable de una línea afectada por el problema de entrega. SIEMPRE lleva nombre + SKU del
 * producto (regla dura: nunca "bulto", nunca null/vacío donde se muestra). El valor afectado de la línea
 * es `unit_price × cantidad afectada` (en centavos).
 */
final readonly class AffectedLine
{
    private function __construct(
        public string $productName,
        public string $sku,
        public int $affectedQuantity,
        public Money $unitPrice,
    ) {}

    public static function of(string $productName, string $sku, int $affectedQuantity, Money $unitPrice): self
    {
        $productName = trim($productName);
        $sku = trim($sku);
        if ($productName === '') {
            throw CompensationException::because('El nombre del producto de la línea afectada no puede estar vacío.');
        }
        if ($sku === '') {
            throw CompensationException::because('El SKU de la línea afectada no puede estar vacío.');
        }
        if ($affectedQuantity < 1) {
            throw CompensationException::because("La cantidad afectada debe ser al menos 1 (recibido: {$affectedQuantity}).");
        }

        return new self($productName, $sku, $affectedQuantity, $unitPrice);
    }

    /**
     * Valor afectado de esta línea: precio unitario × cantidad afectada.
     */
    public function lineValue(): Money
    {
        return Money::of($this->unitPrice->cents * $this->affectedQuantity, $this->unitPrice->currency);
    }

    /**
     * @return array{product_name: string, sku: string, affected_quantity: int, unit_price_cents: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'product_name' => $this->productName,
            'sku' => $this->sku,
            'affected_quantity' => $this->affectedQuantity,
            'unit_price_cents' => $this->unitPrice->cents,
            'currency' => $this->unitPrice->currency,
        ];
    }

    /**
     * @param array{product_name: string, sku: string, affected_quantity: int, unit_price_cents: int, currency: string} $data
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            $data['product_name'],
            $data['sku'],
            $data['affected_quantity'],
            Money::of($data['unit_price_cents'], $data['currency']),
        );
    }
}
