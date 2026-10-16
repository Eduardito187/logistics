<?php

declare(strict_types=1);

namespace Logistics\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryLotRecord;

/**
 * Seeder propio del contexto Inventory con lotes de demo realistas.
 *
 * Idempotente y UUID-estable (skill [[no-destructive-db-ops]]): `firstOrCreate` por id v7 fijo, de modo
 * que un segundo run no duplica ni regenera ids. Siembra lotes propios de Dismac y lotes en consignación
 * de los sellers demo (owner_party_id = ids de SellersDemoSeeder), para ver la segregación por dueño en el
 * admin. El integrador invoca este seeder desde el DatabaseSeeder raíz, después del de sellers.
 */
final class InventoryDemoSeeder extends Seeder
{
    /** Almacén central de demo (node id estable). */
    private const CENTRAL_WAREHOUSE = '01931000-0000-7000-8000-00000000c001';

    /** Seller demo 1 (Boliviana de Electrodomésticos) — ver SellersDemoSeeder. */
    private const SELLER_ONE = '01931000-0000-7000-8000-000000000001';

    /** Seller demo 2 (Andina Home & Living) — ver SellersDemoSeeder. */
    private const SELLER_TWO = '01931000-0000-7000-8000-000000000002';

    /**
     * Lotes de demo con ids v7 estables.
     *
     * @var list<array{id: string, sku: string, fulfillment_mode: string, owner_party_id: ?string, seller_sku_ref: ?string, available_quantity: int, reserved_quantity: int, unit_cost_cents: int, goods_receipt_ref: ?string}>
     */
    private const LOTS = [
        [
            'id' => '01931000-0000-7000-8000-00000000d001',
            'sku' => 'DIS-WASH-900',
            'fulfillment_mode' => 'owned',
            'owner_party_id' => null,
            'seller_sku_ref' => null,
            'available_quantity' => 120,
            'reserved_quantity' => 10,
            'unit_cost_cents' => 180000,
            'goods_receipt_ref' => null,
        ],
        [
            'id' => '01931000-0000-7000-8000-00000000d002',
            'sku' => 'DIS-MICRO-20',
            'fulfillment_mode' => 'owned',
            'owner_party_id' => null,
            'seller_sku_ref' => null,
            'available_quantity' => 300,
            'reserved_quantity' => 0,
            'unit_cost_cents' => 45000,
            'goods_receipt_ref' => null,
        ],
        [
            'id' => '01931000-0000-7000-8000-00000000d003',
            'sku' => 'BEL-TV-55',
            'fulfillment_mode' => 'fbd',
            'owner_party_id' => self::SELLER_ONE,
            'seller_sku_ref' => 'BEL-TV-55',
            'available_quantity' => 40,
            'reserved_quantity' => 5,
            'unit_cost_cents' => 320000,
            'goods_receipt_ref' => 'GR-5001001-0001',
        ],
        [
            'id' => '01931000-0000-7000-8000-00000000d004',
            'sku' => 'AHL-SOFA-3P',
            'fulfillment_mode' => 'fbd',
            'owner_party_id' => self::SELLER_TWO,
            'seller_sku_ref' => 'AHL-SOFA-3P',
            'available_quantity' => 18,
            'reserved_quantity' => 2,
            'unit_cost_cents' => 210000,
            'goods_receipt_ref' => 'GR-5001002-0001',
        ],
    ];

    public function run(): void
    {
        foreach (self::LOTS as $lot) {
            InventoryLotRecord::query()->firstOrCreate(
                ['id' => $lot['id']],
                [
                    'warehouse_id' => self::CENTRAL_WAREHOUSE,
                    'sku' => $lot['sku'],
                    'fulfillment_mode' => $lot['fulfillment_mode'],
                    'owner_party_id' => $lot['owner_party_id'],
                    'seller_sku_ref' => $lot['seller_sku_ref'],
                    'available_quantity' => $lot['available_quantity'],
                    'reserved_quantity' => $lot['reserved_quantity'],
                    'unit_cost_cents' => $lot['unit_cost_cents'],
                    'currency' => 'BOB',
                    'received_at' => Carbon::now()->subDays(7),
                    'goods_receipt_ref' => $lot['goods_receipt_ref'],
                ],
            );
        }
    }
}
