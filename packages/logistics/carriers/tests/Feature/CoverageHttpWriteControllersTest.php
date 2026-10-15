<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\SettlementFactory;

/**
 * Cobertura de los controllers HTTP de escritura del contexto carriers (Patrón F): recorre cada ruta
 * real de las puertas `integrations` y `app`, atravesando controller → FormRequest → CommandBus →
 * handler → dominio → mapper/repositorio → outbox en una sola ida y vuelta.
 *
 * Se omite el middleware de puerta (auth/device) para ejercitar solo el contrato del payload, igual que
 * CarrierNameEnrichmentTest. Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('registra un transportista nuevo (202) y persiste la fila', function (): void {
    $this->postJson('/api/v1/integrations/carriers', [
        'name' => 'Transportes Nuevo Horizonte',
        'tax_id' => 'NIT-9988776',
        'documents' => [
            ['type' => 'habilitacion', 'reference' => 'HAB-001'],
        ],
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.name', 'Transportes Nuevo Horizonte');

    expect(DB::table('carriers')->where('name', 'Transportes Nuevo Horizonte')->count())->toBe(1);
});

it('rechaza el alta de transportista sin nombre (422 de validación)', function (): void {
    $this->postJson('/api/v1/integrations/carriers', [
        'tax_id' => 'NIT-0000001',
    ])->assertStatus(422);
});

it('suspende y luego reactiva un transportista', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Flota Intermitente']);

    $this->postJson("/api/v1/integrations/carriers/{$carrier->id}/suspend")
        ->assertStatus(202)
        ->assertJsonPath('data.carrier_id', $carrier->id);

    expect(DB::table('carriers')->where('id', $carrier->id)->value('status'))->toBe('suspended');

    $this->postJson("/api/v1/integrations/carriers/{$carrier->id}/reactivate")
        ->assertStatus(202);

    expect(DB::table('carriers')->where('id', $carrier->id)->value('status'))->toBe('active');
});

it('define la tarifa de un corredor para un transportista (202) y la persiste', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Tarifas del Valle']);

    $this->postJson("/api/v1/integrations/carriers/{$carrier->id}/tariffs", [
        'corridor_from' => 'CD-LPZ',
        'corridor_to' => 'CD-SCZ',
        'corridor_type' => 'cd_to_cd',
        'vehicle_class' => 'trailer',
        'price_cents' => 150_000,
        'currency' => 'BOB',
        'effective_from' => '2026-01-01',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.carrier_id', $carrier->id);

    expect(DB::table('carrier_tariffs')->where('carrier_id', $carrier->id)->count())->toBe(1);
});

it('devuelve 422 de dominio al tarifar un transportista inexistente', function (): void {
    $fantasma = CarrierId::generate()->toString();

    $this->postJson("/api/v1/integrations/carriers/{$fantasma}/tariffs", [
        'corridor_from' => 'CD-LPZ',
        'corridor_to' => 'CD-SCZ',
        'corridor_type' => 'cd_to_cd',
        'vehicle_class' => 'trailer',
        'price_cents' => 150_000,
        'currency' => 'BOB',
        'effective_from' => '2026-01-01',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('rechaza una tarifa con clase de vehículo inválida (422 de validación)', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Tarifas Mal Formadas']);

    $this->postJson("/api/v1/integrations/carriers/{$carrier->id}/tariffs", [
        'corridor_from' => 'CD-LPZ',
        'corridor_to' => 'CD-SCZ',
        'corridor_type' => 'cd_to_cd',
        'vehicle_class' => 'nave_espacial',
        'price_cents' => 150_000,
        'currency' => 'BOB',
        'effective_from' => '2026-01-01',
    ])->assertStatus(422);
});

it('registra una calificación de desempeño del transportista', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Servicio Calificable']);

    $this->postJson("/api/v1/integrations/carriers/{$carrier->id}/ratings", [
        'rating' => 5,
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.rating', 5);

    expect(DB::table('carriers')->where('id', $carrier->id)->value('rating_count'))->toBe(1);
});

it('rechaza una calificación fuera del rango 1..5 (422 de validación)', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Servicio Fuera de Rango']);

    $this->postJson("/api/v1/integrations/carriers/{$carrier->id}/ratings", [
        'rating' => 9,
    ])->assertStatus(422);
});

it('un transportista envía una liquidación (202) y se persiste', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Liquida Express']);

    $this->postJson("/api/v1/integrations/carriers/{$carrier->id}/settlements", [
        'corridor_from' => 'CD-LPZ',
        'corridor_to' => 'CD-CBB',
        'corridor_type' => 'cd_to_cd',
        'period' => '2026-03',
        'amount_cents' => 450_000,
        'currency' => 'BOB',
        'reference' => 'FAC-7788',
        'documents' => [
            ['type' => 'factura', 'reference' => 'FAC-7788'],
        ],
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.carrier_id', $carrier->id);

    expect(DB::table('carrier_settlements')->where('carrier_id', $carrier->id)->count())->toBe(1);
});

it('adjunta un documento a una liquidación enviada', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Docs Adjuntos']);
    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->create();

    $this->postJson("/api/v1/integrations/carriers/settlements/{$settlement->id}/documents", [
        'type' => 'comprobante',
        'reference' => 'COMP-321',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.settlement_id', $settlement->id);
});

it('aprueba una liquidación enviada y luego la marca pagada', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Aprobar y Pagar']);
    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->create();

    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/approve")
        ->assertStatus(202)
        ->assertJsonPath('data.settlement_id', $settlement->id);

    expect(DB::table('carrier_settlements')->where('id', $settlement->id)->value('status'))->toBe('approved');

    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/pay", [
        'payment_reference' => 'TRF-55443',
    ])->assertStatus(202);

    expect(DB::table('carrier_settlements')->where('id', $settlement->id)->value('status'))->toBe('paid');
});

it('devuelve 422 de dominio al pagar una liquidación que no está aprobada', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Pago Prematuro']);
    // Liquidación en estado submitted: no se puede pagar directamente.
    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->create();

    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/pay", [
        'payment_reference' => 'TRF-00000',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('objeta una liquidación enviada con un motivo', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Objetable']);
    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->create();

    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/dispute", [
        'reason' => 'El monto no coincide con lo pactado.',
    ])->assertStatus(202);

    expect(DB::table('carrier_settlements')->where('id', $settlement->id)->value('status'))->toBe('disputed');
});
