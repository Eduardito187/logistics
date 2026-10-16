<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Inventory\Domain\ValueObject\LotId;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryLotRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de lotes de stock para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para coincidir con producción. Por defecto
 * crea un lote propio de Dismac (owned); el state `consignment()` lo convierte en consignación de un seller.
 *
 * @extends Factory<InventoryLotRecord>
 */
final class InventoryLotRecordFactory extends Factory
{
    protected $model = InventoryLotRecord::class;

    public function definition(): array
    {
        return [
            'id' => LotId::generate()->toString(),
            'warehouse_id' => Uuid::generate()->value,
            'sku' => 'SKU-'.$this->faker->unique()->numerify('######'),
            'fulfillment_mode' => 'owned',
            'owner_party_id' => null,
            'seller_sku_ref' => null,
            'available_quantity' => $this->faker->numberBetween(1, 500),
            'reserved_quantity' => 0,
            'unit_cost_cents' => $this->faker->numberBetween(1000, 500000),
            'currency' => 'BOB',
            'received_at' => now(),
            'goods_receipt_ref' => null,
        ];
    }

    /**
     * Lote en consignación de un seller (producto que no es de Dismac).
     */
    public function consignment(string $ownerPartyId, ?string $sellerSkuRef = null): self
    {
        return $this->state(fn (): array => [
            'fulfillment_mode' => 'fbd',
            'owner_party_id' => $ownerPartyId,
            'seller_sku_ref' => $sellerSkuRef,
        ]);
    }
}
