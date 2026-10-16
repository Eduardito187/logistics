<?php

declare(strict_types=1);

namespace Logistics\Sellers\Database\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerSettlementTermsRecord;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerSkuRecord;

/**
 * Seeder propio del contexto Sellers con datos de demo realistas.
 *
 * Idempotente y UUID-estable (skill [[no-destructive-db-ops]]): usa `firstOrCreate` por clave estable y
 * UUIDs v7 fijos, de modo que un segundo run no duplica ni regenera ids (los deep-links del admin siguen
 * válidos). El integrador invoca este seeder desde el DatabaseSeeder raíz.
 *
 * Siembra directamente la proyección de lectura (records Eloquent): es la vía estándar de demo y no
 * depende de que el outbox esté presente.
 */
final class SellersDemoSeeder extends Seeder
{
    /**
     * Sellers de demo con ids v7 estables, términos de liquidación y un par de SKUs.
     *
     * @var list<array{id: string, legal_name: string, tax_id: string, status: string, contact_name: string, email: string, phone: string, terms_id: string, commission_basis_points: int, fixed_fee_cents: int, payment_cycle: string, skus: list<array{id: string, sku: string, sap_material: ?string}>}>
     */
    private const SELLERS = [
        [
            'id' => '01931000-0000-7000-8000-000000000001',
            'legal_name' => 'Boliviana de Electrodomésticos S.R.L.',
            'tax_id' => 'NIT-5001001',
            'status' => 'active',
            'contact_name' => 'María Fernanda Ríos',
            'email' => 'ventas@boleldom.bo',
            'phone' => '70011001',
            'terms_id' => '01931000-0000-7000-8000-0000000000a1',
            'commission_basis_points' => 1200,
            'fixed_fee_cents' => 1500,
            'payment_cycle' => 'biweekly',
            'skus' => [
                ['id' => '01931000-0000-7000-8000-0000000000b1', 'sku' => 'BEL-TV-55', 'sap_material' => 'MAT-900155'],
                ['id' => '01931000-0000-7000-8000-0000000000b2', 'sku' => 'BEL-REF-300', 'sap_material' => null],
            ],
        ],
        [
            'id' => '01931000-0000-7000-8000-000000000002',
            'legal_name' => 'Andina Home & Living Ltda.',
            'tax_id' => 'NIT-5001002',
            'status' => 'active',
            'contact_name' => 'José Luis Vargas',
            'email' => 'marketplace@andinahome.bo',
            'phone' => '70011002',
            'terms_id' => '01931000-0000-7000-8000-0000000000a2',
            'commission_basis_points' => 1800,
            'fixed_fee_cents' => 2000,
            'payment_cycle' => 'monthly',
            'skus' => [
                ['id' => '01931000-0000-7000-8000-0000000000b3', 'sku' => 'AHL-SOFA-3P', 'sap_material' => null],
            ],
        ],
        [
            'id' => '01931000-0000-7000-8000-000000000003',
            'legal_name' => 'TecnoImport Santa Cruz S.A.',
            'tax_id' => 'NIT-5001003',
            'status' => 'onboarding',
            'contact_name' => 'Carla Suárez',
            'email' => 'onboarding@tecnoimport.bo',
            'phone' => '70011003',
            'terms_id' => '01931000-0000-7000-8000-0000000000a3',
            'commission_basis_points' => 1000,
            'fixed_fee_cents' => 0,
            'payment_cycle' => 'weekly',
            'skus' => [],
        ],
    ];

    public function run(): void
    {
        foreach (self::SELLERS as $seller) {
            SellerRecord::query()->firstOrCreate(
                ['tax_id' => $seller['tax_id']],
                [
                    'id' => $seller['id'],
                    'legal_name' => $seller['legal_name'],
                    'status' => $seller['status'],
                    'contact_name' => $seller['contact_name'],
                    'contact_email' => $seller['email'],
                    'contact_phone' => $seller['phone'],
                ],
            );

            SellerSettlementTermsRecord::query()->firstOrCreate(
                ['seller_id' => $seller['id']],
                [
                    'id' => $seller['terms_id'],
                    'commission_basis_points' => $seller['commission_basis_points'],
                    'fixed_fee_cents' => $seller['fixed_fee_cents'],
                    'currency' => 'BOB',
                    'payment_cycle' => $seller['payment_cycle'],
                ],
            );

            foreach ($seller['skus'] as $sku) {
                SellerSkuRecord::query()->firstOrCreate(
                    ['seller_id' => $seller['id'], 'seller_sku' => $sku['sku']],
                    [
                        'id' => $sku['id'],
                        'sap_material' => $sku['sap_material'],
                    ],
                );
            }
        }
    }
}
