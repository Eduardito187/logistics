<?php

declare(strict_types=1);

namespace Logistics\Sellers\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerSkuRecord;

/**
 * Fábrica de SKUs del catálogo de un seller para seeders de demo y pruebas Feature.
 *
 * @extends Factory<SellerSkuRecord>
 */
final class SellerSkuFactory extends Factory
{
    protected $model = SellerSkuRecord::class;

    public function definition(): array
    {
        return [
            'id' => SellerId::generate()->toString(),
            'seller_id' => SellerId::generate()->toString(),
            'seller_sku' => 'SKU-'.$this->faker->unique()->numerify('######'),
            'sap_material' => null,
        ];
    }

    /**
     * SKU para un seller concreto.
     */
    public function forSeller(string $sellerId): self
    {
        return $this->state(fn (): array => ['seller_id' => $sellerId]);
    }

    /**
     * SKU mapeado a un material de SAP.
     */
    public function mappedTo(string $sapMaterial): self
    {
        return $this->state(fn (): array => ['sap_material' => $sapMaterial]);
    }
}
