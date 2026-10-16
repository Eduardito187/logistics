<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\ValueObject;

use Logistics\Sellers\Domain\Exception\SellersException;

/**
 * SKU del catálogo de un seller: el código propio del seller y, opcionalmente, su equivalencia con un
 * material de SAP (cuando Dismac lo mapea para la frontera contable, ADR 002). Value object inmutable.
 *
 * El material SAP es opcional a propósito: en Fase 1 muchos sellers aún no tienen mapeo; se completa
 * cuando el sap-connector lo necesite (Fase 3).
 */
final readonly class SellerSku
{
    private function __construct(
        public string $sku,
        public ?string $sapMaterial,
    ) {}

    public static function of(string $sku, ?string $sapMaterial = null): self
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw SellersException::because('El SKU del seller no puede estar vacío.');
        }

        $sapMaterial = $sapMaterial !== null ? trim($sapMaterial) : null;
        if ($sapMaterial === '') {
            $sapMaterial = null;
        }

        return new self($sku, $sapMaterial);
    }

    public function equals(self $other): bool
    {
        return $this->sku === $other->sku && $this->sapMaterial === $other->sapMaterial;
    }

    /**
     * @return array{sku: string, sap_material: ?string}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'sap_material' => $this->sapMaterial,
        ];
    }
}
