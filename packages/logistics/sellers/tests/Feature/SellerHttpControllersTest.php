<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Sellers\Database\Factories\SellerFactory;
use Logistics\Sellers\Domain\ValueObject\SellerId;

/**
 * Cobertura de los controllers HTTP del contexto sellers (puerta `integrations`): recorre cada ruta real
 * atravesando ApiController (envelopes data/accepted/error + guard) → FormRequest → CommandBus/QueryBus →
 * handler → dominio → repositorio → outbox en una sola ida y vuelta.
 *
 * Se omite el middleware de puerta (service.auth) para ejercitar solo el contrato del payload, igual que
 * CoverageHttpWriteControllersTest de carriers. Corre contra logistics_test (RefreshDatabase); skip sin
 * PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('registra un seller (202), persiste la fila y encola el evento en el outbox', function (): void {
    $this->postJson('/api/v1/integrations/sellers', [
        'legal_name' => 'Boliviana de Electrodomésticos S.R.L.',
        'tax_id' => 'NIT-HTTP-0001',
        'contact_name' => 'María Ríos',
        'email' => 'ventas@boleldom.bo',
        'phone' => '70011001',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.tax_id', 'NIT-HTTP-0001');

    $this->assertDatabaseHas('sellers', [
        'tax_id' => 'NIT-HTTP-0001',
        'status' => 'onboarding',
    ]);

    expect(DB::table('outbox_messages')->where('event_name', 'sellers.seller_registered')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('registra un seller sin teléfono (opcional)', function (): void {
    $this->postJson('/api/v1/integrations/sellers', [
        'legal_name' => 'Andina Home & Living Ltda.',
        'tax_id' => 'NIT-HTTP-0002',
        'contact_name' => 'José Vargas',
        'email' => 'marketplace@andinahome.bo',
    ])->assertStatus(202);

    expect(DB::table('sellers')->where('tax_id', 'NIT-HTTP-0002')->value('contact_phone'))->toBeNull();
});

it('rechaza el alta sin nombre legal (422 de validación)', function (): void {
    $this->postJson('/api/v1/integrations/sellers', [
        'tax_id' => 'NIT-HTTP-9999',
        'contact_name' => 'Sin Nombre',
        'email' => 'sin@nombre.bo',
    ])->assertStatus(422);
});

it('lista los sellers como proyección de lectura', function (): void {
    SellerFactory::new()->create(['legal_name' => 'Alfa Retail', 'tax_id' => 'NIT-HTTP-L001']);
    SellerFactory::new()->create(['legal_name' => 'Beta Retail', 'tax_id' => 'NIT-HTTP-L002']);

    $this->getJson('/api/v1/integrations/sellers')
        ->assertStatus(200)
        ->assertJsonPath('data.0.legal_name', 'Alfa Retail');
});

it('pagina el listado de forma aditiva cuando el cliente lo pide', function (): void {
    SellerFactory::new()->create(['legal_name' => 'Gamma Retail', 'tax_id' => 'NIT-HTTP-P001']);
    SellerFactory::new()->create(['legal_name' => 'Delta Retail', 'tax_id' => 'NIT-HTTP-P002']);

    $this->getJson('/api/v1/integrations/sellers?per_page=1')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data', 'meta']);
});

it('filtra el listado por estado', function (): void {
    SellerFactory::new()->active()->create(['legal_name' => 'Activo Uno', 'tax_id' => 'NIT-HTTP-F001']);
    SellerFactory::new()->suspended()->create(['legal_name' => 'Suspendido Uno', 'tax_id' => 'NIT-HTTP-F002']);

    $response = $this->getJson('/api/v1/integrations/sellers?status=suspended')->assertStatus(200);

    $taxIds = array_column((array) $response->json('data'), 'tax_id');
    expect($taxIds)->toContain('NIT-HTTP-F002')
        ->and($taxIds)->not->toContain('NIT-HTTP-F001');
});

it('devuelve un seller por su id (200)', function (): void {
    $seller = SellerFactory::new()->create(['legal_name' => 'Ficha Visible', 'tax_id' => 'NIT-HTTP-S001']);

    $this->getJson("/api/v1/integrations/sellers/{$seller->getKey()}")
        ->assertStatus(200)
        ->assertJsonPath('data.legal_name', 'Ficha Visible')
        ->assertJsonPath('data.tax_id', 'NIT-HTTP-S001');
});

it('devuelve 404 para un seller inexistente', function (): void {
    $this->getJson('/api/v1/integrations/sellers/'.SellerId::generate()->toString())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('pacta términos de liquidación (202) y los persiste', function (): void {
    $seller = SellerFactory::new()->active()->create(['tax_id' => 'NIT-HTTP-T001']);

    $this->putJson("/api/v1/integrations/sellers/{$seller->getKey()}/settlement-terms", [
        'commission_basis_points' => 1800,
        'fixed_fee_cents' => 2500,
        'currency' => 'BOB',
        'payment_cycle' => 'monthly',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.seller_id', (string) $seller->getKey());

    $this->assertDatabaseHas('seller_settlement_terms', [
        'seller_id' => (string) $seller->getKey(),
        'commission_basis_points' => 1800,
        'fixed_fee_cents' => 2500,
        'payment_cycle' => 'monthly',
    ]);
});

it('usa BOB por defecto cuando no se envía moneda en los términos', function (): void {
    $seller = SellerFactory::new()->active()->create(['tax_id' => 'NIT-HTTP-T002']);

    $this->putJson("/api/v1/integrations/sellers/{$seller->getKey()}/settlement-terms", [
        'commission_basis_points' => 1000,
        'fixed_fee_cents' => 0,
        'payment_cycle' => 'weekly',
    ])->assertStatus(202);

    expect(DB::table('seller_settlement_terms')->where('seller_id', $seller->getKey())->value('currency'))
        ->toBe('BOB');
});

it('rechaza términos con ciclo de pago inválido (422 de validación)', function (): void {
    $seller = SellerFactory::new()->active()->create(['tax_id' => 'NIT-HTTP-T003']);

    $this->putJson("/api/v1/integrations/sellers/{$seller->getKey()}/settlement-terms", [
        'commission_basis_points' => 1000,
        'fixed_fee_cents' => 0,
        'payment_cycle' => 'anual',
    ])->assertStatus(422);
});

it('devuelve 422 de dominio al pactar términos sobre un seller inexistente', function (): void {
    $this->putJson('/api/v1/integrations/sellers/'.SellerId::generate()->toString().'/settlement-terms', [
        'commission_basis_points' => 1000,
        'fixed_fee_cents' => 0,
        'payment_cycle' => 'monthly',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('da de alta un SKU del catálogo (202) y lo persiste', function (): void {
    $seller = SellerFactory::new()->create(['tax_id' => 'NIT-HTTP-K001']);

    $this->postJson("/api/v1/integrations/sellers/{$seller->getKey()}/skus", [
        'sku' => 'BEL-TV-55',
        'sap_material' => 'MAT-900155',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.sku', 'BEL-TV-55');

    $this->assertDatabaseHas('seller_skus', [
        'seller_id' => (string) $seller->getKey(),
        'seller_sku' => 'BEL-TV-55',
        'sap_material' => 'MAT-900155',
    ]);
});

it('rechaza un SKU sin código (422 de validación)', function (): void {
    $seller = SellerFactory::new()->create(['tax_id' => 'NIT-HTTP-K002']);

    $this->postJson("/api/v1/integrations/sellers/{$seller->getKey()}/skus", [
        'sap_material' => 'MAT-1',
    ])->assertStatus(422);
});

it('devuelve 422 de dominio al cargar un SKU en un seller inexistente', function (): void {
    $this->postJson('/api/v1/integrations/sellers/'.SellerId::generate()->toString().'/skus', [
        'sku' => 'X-1',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});
