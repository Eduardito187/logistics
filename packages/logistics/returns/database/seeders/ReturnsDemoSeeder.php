<?php

declare(strict_types=1);

namespace Logistics\Returns\Database\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnLineRecord;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnRecord;

/**
 * Seeder de demo del contexto Returns: dos devoluciones de ejemplo.
 *
 *  1. Devolución de cliente de producto de un seller (FBD), ya dispuesta como Restock (vuelve al stock).
 *  2. Devolución de cliente de producto retail (sin dueño), recibida y pendiente de disposición.
 *
 * Idempotente y UUID-estable (skill [[no-destructive-db-ops]]): usa `firstOrCreate` por clave estable y
 * UUIDs v7 fijos; un segundo run no duplica ni regenera ids. El seller y el almacén referenciados coinciden
 * con SellersDemoSeeder e InboundDemoSeeder para que el admin muestre nombres reales.
 */
final class ReturnsDemoSeeder extends Seeder
{
    /** Devolución de seller ya dispuesta (restock). */
    private const SELLER_RETURN_ID = '01931002-0000-7000-8000-000000000001';

    private const SELLER_RETURN_LINE_ID = '01931002-0000-7000-8000-0000000000a1';

    /** Devolución retail recibida, pendiente de disposición. */
    private const RETAIL_RETURN_ID = '01931002-0000-7000-8000-000000000002';

    private const RETAIL_RETURN_LINE_ID = '01931002-0000-7000-8000-0000000000a2';

    /** Seller dueño del producto (coincide con SellersDemoSeeder). */
    private const SELLER_ID = '01931000-0000-7000-8000-000000000001';

    /** Almacén central de retorno (coincide con InboundDemoSeeder). */
    private const WAREHOUSE_ID = '01931001-0000-7000-8000-0000000000f1';

    public function run(): void
    {
        ReturnRecord::query()->firstOrCreate(
            ['id' => self::SELLER_RETURN_ID],
            [
                'original_reference' => 'SHP-20260012',
                'owner_party_id' => self::SELLER_ID,
                'fulfillment_mode' => 'fbd',
                'warehouse_id' => self::WAREHOUSE_ID,
                'kind' => 'customer_return',
                'disposition' => 'restock',
                'status' => 'dispositioned',
            ],
        );

        ReturnLineRecord::query()->firstOrCreate(
            ['id' => self::SELLER_RETURN_LINE_ID],
            [
                'return_id' => self::SELLER_RETURN_ID,
                'sku' => 'BEL-TV-55',
                'quantity' => 1,
                'reason' => 'customer_regret',
            ],
        );

        ReturnRecord::query()->firstOrCreate(
            ['id' => self::RETAIL_RETURN_ID],
            [
                'original_reference' => 'SHP-20260031',
                'owner_party_id' => null,
                'fulfillment_mode' => 'owned',
                'warehouse_id' => self::WAREHOUSE_ID,
                'kind' => 'customer_return',
                'disposition' => null,
                'status' => 'received',
            ],
        );

        ReturnLineRecord::query()->firstOrCreate(
            ['id' => self::RETAIL_RETURN_LINE_ID],
            [
                'return_id' => self::RETAIL_RETURN_ID,
                'sku' => 'DIS-FRIDGE-300',
                'quantity' => 1,
                'reason' => 'damaged',
            ],
        );
    }
}
