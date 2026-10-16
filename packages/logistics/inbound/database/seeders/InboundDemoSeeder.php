<?php

declare(strict_types=1);

namespace Logistics\Inbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnLineRecord;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnRecord;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\GoodsReceiptRecord;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\ReceiptLineRecord;

/**
 * Seeder de demo del contexto Inbound: UN ASN ya recibido y etiquetado de un seller FBD.
 *
 * Idempotente y UUID-estable (skill [[no-destructive-db-ops]]): usa `firstOrCreate` por clave estable y
 * UUIDs v7 fijos; un segundo run no duplica ni regenera ids. El seller referenciado es el de SellersDemoSeeder
 * (Boliviana de Electrodomésticos), para que el admin muestre un nombre real junto al ASN.
 */
final class InboundDemoSeeder extends Seeder
{
    /** ASN de demo (recibido + etiquetado). */
    private const ASN_ID = '01931001-0000-7000-8000-000000000001';

    private const ASN_LINE_ID = '01931001-0000-7000-8000-0000000000a1';

    private const RECEIPT_ID = '01931001-0000-7000-8000-0000000000b1';

    private const RECEIPT_LINE_ID = '01931001-0000-7000-8000-0000000000c1';

    /** Seller dueño del producto (coincide con SellersDemoSeeder). */
    private const SELLER_ID = '01931000-0000-7000-8000-000000000001';

    /** Almacén central destino (nodo de red; id estable de demo). */
    private const WAREHOUSE_ID = '01931001-0000-7000-8000-0000000000f1';

    private const SKU = 'BEL-TV-55';

    public function run(): void
    {
        AsnRecord::query()->firstOrCreate(
            ['id' => self::ASN_ID],
            [
                'seller_id' => self::SELLER_ID,
                'warehouse_id' => self::WAREHOUSE_ID,
                'status' => 'received',
                'appointment_date' => '2026-10-20',
                'appointment_start' => '08:00',
                'appointment_end' => '12:00',
            ],
        );

        AsnLineRecord::query()->firstOrCreate(
            ['id' => self::ASN_LINE_ID],
            [
                'asn_id' => self::ASN_ID,
                'sku' => self::SKU,
                'expected_quantity' => 10,
            ],
        );

        GoodsReceiptRecord::query()->firstOrCreate(
            ['id' => self::RECEIPT_ID],
            [
                'asn_id' => self::ASN_ID,
                'warehouse_id' => self::WAREHOUSE_ID,
                'owner_party_id' => self::SELLER_ID,
                'fulfillment_mode' => 'fbd',
                'seller_sku_ref' => self::SKU,
                'labeled' => true,
            ],
        );

        ReceiptLineRecord::query()->firstOrCreate(
            ['id' => self::RECEIPT_LINE_ID],
            [
                'goods_receipt_id' => self::RECEIPT_ID,
                'sku' => self::SKU,
                'accepted_quantity' => 9,
                'rejected_quantity' => 0,
                'damaged_quantity' => 1,
                'qc_outcome' => 'partial_damage',
            ],
        );
    }
}
