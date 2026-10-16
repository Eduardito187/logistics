<?php

declare(strict_types=1);

namespace Logistics\Inbound\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\GoodsReceiptId;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\GoodsReceiptRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de recepciones (goods receipts) para seeders de demo y pruebas Feature.
 *
 * @extends Factory<GoodsReceiptRecord>
 */
final class GoodsReceiptRecordFactory extends Factory
{
    protected $model = GoodsReceiptRecord::class;

    public function definition(): array
    {
        return [
            'id' => GoodsReceiptId::generate()->toString(),
            'asn_id' => AsnId::generate()->toString(),
            'warehouse_id' => Uuid::generate()->value,
            'owner_party_id' => Uuid::generate()->value,
            'fulfillment_mode' => 'fbd',
            'seller_sku_ref' => null,
            'labeled' => false,
        ];
    }
}
