<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory\SellerSettlementFactory;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory\SellerSettlementLineFactory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los controllers HTTP del contexto Settlement (Patrón F, igual que CoverageHttpWriteControllersTest
 * de carriers): recorre cada ruta real de la puerta `integrations` atravesando controller → FormRequest →
 * bus CQRS → handler → dominio → mapper/repositorio → outbox en una sola ida y vuelta.
 *
 * Se omite el middleware de puerta (service.auth/idempotency) para ejercitar solo el contrato del payload.
 * Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un seller y dos despachos entregados suyos en el periodo, para que el borrador agregue sus líneas.
 * El sufijo mantiene únicos los external_id entre corridas paralelas.
 *
 * @return string el id del seller sembrado
 */
function settlementHttpSeedSeller(): string
{
    $suffix = strtoupper(substr(str_replace('-', '', Uuid::generate()->value), -6));
    $sellerId = Uuid::generate()->value;

    DB::table('sellers')->insert([
        'id' => $sellerId,
        'legal_name' => 'HTTP Seller '.$suffix,
        'tax_id' => 'NIT-HTTP-'.$suffix,
        'status' => 'active',
        'contact_name' => 'Owner',
        'contact_email' => 'owner'.$suffix.'@seller.bo',
        'contact_phone' => '70000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([['DEL-'.$suffix.'-1', 300, 20.00], ['DEL-'.$suffix.'-2', 200, 20.00]] as [$ext, $qty, $unit]) {
        $shipmentId = Uuid::generate()->value;
        DB::table('shipments')->insert([
            'id' => $shipmentId,
            'source' => 'seller-portal',
            'external_id' => $ext,
            'customer_name' => 'Cliente',
            'address_text' => 'Av. Siempre Viva 123',
            'address_confidence' => 90,
            'address_confidence_level' => 'high',
            'status' => 'DELIVERED',
            'owner_party_id' => $sellerId,
            'fulfillment_mode' => 'fbd',
            'created_at' => '2026-09-10 10:00:00',
            'updated_at' => '2026-09-15 10:00:00',
        ]);
        DB::table('shipment_lines')->insert([
            'id' => Uuid::generate()->value,
            'shipment_id' => $shipmentId,
            'sku' => 'SKU-'.$ext,
            'product_name' => 'Producto',
            'quantity' => $qty,
            'unit_price' => $unit,
            'unit_price_currency' => 'BOB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $sellerId;
}

it('arma un borrador por HTTP (202) agregando los despachos y encola el evento', function (): void {
    $sellerId = settlementHttpSeedSeller();

    $this->postJson('/api/v1/integrations/settlements/draft', [
        'seller_id' => $sellerId,
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'commission_basis_points' => 1500,
        'fixed_fee_cents' => 2_000,
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.seller_id', $sellerId);

    $record = DB::table('seller_settlements')->where('seller_id', $sellerId)->first();
    expect($record)->not->toBeNull()
        ->and((int) $record->gross_cents)->toBe(1_000_000)
        ->and((int) $record->net_cents)->toBe(846_000);

    expect(DB::table('outbox_messages')->where('event_name', 'settlement.drafted')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('arma un borrador por HTTP respetando la moneda explícita del cuerpo', function (): void {
    $sellerId = settlementHttpSeedSeller();

    $this->postJson('/api/v1/integrations/settlements/draft', [
        'seller_id' => $sellerId,
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'commission_basis_points' => 1000,
        'fixed_fee_cents' => 0,
        'currency' => 'USD',
    ])->assertStatus(202);

    expect(DB::table('seller_settlements')->where('seller_id', $sellerId)->value('currency'))->toBe('USD');
});

it('rechaza un borrador con payload inválido (422 de validación)', function (): void {
    $this->postJson('/api/v1/integrations/settlements/draft', [
        'seller_id' => 'no-es-uuid',
        'period_from' => '2026-09-01',
    ])->assertStatus(422);
});

it('devuelve 422 de dominio al armar un borrador de un seller sin despachos', function (): void {
    $this->postJson('/api/v1/integrations/settlements/draft', [
        'seller_id' => Uuid::generate()->value,
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'commission_basis_points' => 1500,
        'fixed_fee_cents' => 0,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('muestra una liquidación por id (200) con el nombre del seller enriquecido', function (): void {
    $sellerId = Uuid::generate()->value;
    DB::table('sellers')->insert([
        'id' => $sellerId,
        'legal_name' => 'Seller Visible',
        'tax_id' => 'NIT-VIS-01',
        'status' => 'active',
        'contact_name' => 'Owner',
        'contact_email' => 'visible@seller.bo',
        'contact_phone' => '70000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $settlement = SellerSettlementFactory::new()->forSeller($sellerId)->create();
    SellerSettlementLineFactory::new()->forSettlement($settlement->id)->create();

    $this->getJson("/api/v1/integrations/settlements/{$settlement->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $settlement->id)
        ->assertJsonPath('data.seller_name', 'Seller Visible');
});

it('responde 404 al pedir una liquidación inexistente', function (): void {
    $this->getJson('/api/v1/integrations/settlements/'.SettlementId::generate()->toString())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'settlement_not_found');
});

it('lista liquidaciones paginadas filtrando por seller (meta con total)', function (): void {
    $sellerId = Uuid::generate()->value;
    SellerSettlementFactory::new()->forSeller($sellerId)->count(3)->create();

    $this->getJson("/api/v1/integrations/settlements?seller_id={$sellerId}&per_page=2&page=1")
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');
});

it('confirma una liquidación en borrador (202) y la persiste como confirmada', function (): void {
    $settlement = SellerSettlementFactory::new()->create();

    $this->postJson("/api/v1/integrations/settlements/{$settlement->id}/confirm")
        ->assertStatus(202)
        ->assertJsonPath('data.settlement_id', $settlement->id);

    expect(DB::table('seller_settlements')->where('id', $settlement->id)->value('status'))->toBe('confirmed');
});

it('devuelve 422 de dominio al confirmar una liquidación ya confirmada', function (): void {
    $settlement = SellerSettlementFactory::new()->confirmed()->create();

    $this->postJson("/api/v1/integrations/settlements/{$settlement->id}/confirm")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('devuelve 422 de dominio al confirmar una liquidación inexistente', function (): void {
    $this->postJson('/api/v1/integrations/settlements/'.SettlementId::generate()->toString().'/confirm')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('devuelve 422 de dominio al pagar una liquidación inexistente', function (): void {
    $this->postJson('/api/v1/integrations/settlements/'.SettlementId::generate()->toString().'/pay', [
        'payment_reference' => 'TRX-GHOST',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('marca pagada una liquidación confirmada (202) con su referencia', function (): void {
    $settlement = SellerSettlementFactory::new()->confirmed()->create();

    $this->postJson("/api/v1/integrations/settlements/{$settlement->id}/pay", [
        'payment_reference' => 'TRX-HTTP-9001',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.settlement_id', $settlement->id);

    $row = DB::table('seller_settlements')->where('id', $settlement->id)->first();
    expect($row->status)->toBe('paid')
        ->and($row->payment_reference)->toBe('TRX-HTTP-9001');
});

it('rechaza el pago sin referencia (422 de validación)', function (): void {
    $settlement = SellerSettlementFactory::new()->confirmed()->create();

    $this->postJson("/api/v1/integrations/settlements/{$settlement->id}/pay", [])
        ->assertStatus(422);
});

it('devuelve 422 de dominio al pagar una liquidación que no está confirmada', function (): void {
    $settlement = SellerSettlementFactory::new()->create();

    $this->postJson("/api/v1/integrations/settlements/{$settlement->id}/pay", [
        'payment_reference' => 'TRX-HTTP-0000',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});
