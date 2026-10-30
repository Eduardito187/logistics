<?php

declare(strict_types=1);

namespace Commerce\Pricing\Database\Seeders;

use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model\PriceListItemRecord;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model\PriceListRecord;
use Illuminate\Database\Seeder;

/**
 * Seeder propio del contexto Pricing con datos de demo realistas (una lista Retail activa en BOB con
 * precios al público, IVA incluido, de 3 SKUs).
 *
 * Idempotente y UUID-estable (skill [[no-destructive-db-ops]]): usa `firstOrCreate` por clave estable y
 * UUIDs v7 fijos, de modo que un segundo run no duplica ni regenera ids. El integrador invoca este
 * seeder desde el DatabaseSeeder raíz.
 *
 * Siembra directamente la proyección de lectura (records Eloquent): es la vía estándar de demo y no
 * depende de que el outbox esté presente.
 */
final class PricingDemoSeeder extends Seeder
{
    private const PRICE_LIST_ID = '01931100-0000-7000-8000-000000000001';

    private const CHANNEL = 'retail';

    private const CURRENCY = 'BOB';

    /**
     * Precios al público (IVA incluido) en centavos, con id v7 estable por ítem.
     *
     * @var list<array{id: string, sku: string, gross_amount_cents: int}>
     */
    private const ITEMS = [
        ['id' => '01931100-0000-7000-8000-0000000000b1', 'sku' => 'BEL-TV-55', 'gross_amount_cents' => 450000],
        ['id' => '01931100-0000-7000-8000-0000000000b2', 'sku' => 'BEL-REF-300', 'gross_amount_cents' => 780000],
        ['id' => '01931100-0000-7000-8000-0000000000b3', 'sku' => 'AHL-SOFA-3P', 'gross_amount_cents' => 320000],
    ];

    public function run(): void
    {
        PriceListRecord::query()->firstOrCreate(
            ['id' => self::PRICE_LIST_ID],
            [
                'name' => 'Retail Bolivia',
                'currency' => self::CURRENCY,
                'channel' => self::CHANNEL,
                'status' => 'active',
            ],
        );

        foreach (self::ITEMS as $item) {
            PriceListItemRecord::query()->firstOrCreate(
                ['price_list_id' => self::PRICE_LIST_ID, 'sku' => $item['sku']],
                [
                    'id' => $item['id'],
                    'gross_amount_cents' => $item['gross_amount_cents'],
                    'currency' => self::CURRENCY,
                ],
            );
        }
    }
}
