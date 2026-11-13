<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\Contract\DockQuotaReserver;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;

/**
 * Cobertura de los controllers HTTP del contexto inbound (puerta `integrations`) junto con sus FormRequest,
 * el ApiController base (data/accepted/error/guard) y la cadena CQRS detrás (comandos, consultas,
 * ReadModels y repositorios). Se omite el middleware de puerta para ejercitar solo el contrato de los
 * controllers. El cupo de andén se reemplaza por un doble. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();

    app()->instance(DockQuotaReserver::class, new class implements DockQuotaReserver
    {
        public function reserve(string $warehouseId, AppointmentWindow $window): void
        {
            // Doble inerte: no se consume capacity real.
        }
    });
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Anuncia un ASN por el endpoint y devuelve su id, resolviéndolo por el listado del seller.
 */
function inboundAnnounceViaHttp(
    object $test,
    string $sellerId = '01931000-0000-7000-8000-000000000011',
    string $warehouseId = '01931001-0000-7000-8000-0000000000e1',
): string {
    $test->postJson('/api/v1/integrations/inbound/asn', [
        'seller_id' => $sellerId,
        'warehouse_id' => $warehouseId,
        'lines' => [['sku' => 'BEL-TV-55', 'expected_quantity' => 10]],
    ])->assertStatus(202)->assertJsonPath('data.accepted', true);

    $list = $test->getJson('/api/v1/integrations/inbound/asn?seller_id='.$sellerId)->assertOk()->json('data');

    return $list[0]['id'];
}

it('anuncia, lista, obtiene y recorre el ciclo de un ASN por HTTP', function (): void {
    $id = inboundAnnounceViaHttp($this);

    // GET por id: ReadModel serializado.
    $this->getJson('/api/v1/integrations/inbound/asn/'.$id)
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'announced');

    // Schedule (PUT) → 202.
    $this->putJson('/api/v1/integrations/inbound/asn/'.$id.'/schedule', [
        'appointment_date' => '2026-10-20',
        'start_time' => '08:00',
        'end_time' => '12:00',
    ])->assertStatus(202)->assertJsonPath('data.asn_id', $id);
    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'scheduled']);

    // Arrival → 202.
    $this->postJson('/api/v1/integrations/inbound/asn/arrival', [
        'asn_id' => $id,
        'carrier_reference' => 'TRUCK-9',
    ])->assertStatus(202);
    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'arrived']);

    // Receipt con QC → 202.
    $this->postJson('/api/v1/integrations/inbound/asn/receipt', [
        'asn_id' => $id,
        'lines' => [
            ['sku' => 'BEL-TV-55', 'accepted' => 9, 'rejected' => 0, 'damaged' => 1, 'qc_outcome' => 'partial_damage'],
        ],
    ])->assertStatus(202);
    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'received']);

    // Label → 202.
    $this->postJson('/api/v1/integrations/inbound/asn/label', ['asn_id' => $id])->assertStatus(202);
});

it('el listado de ASNs responde con envelope paginado', function (): void {
    inboundAnnounceViaHttp($this, '01931000-0000-7000-8000-000000000012');

    $this->getJson('/api/v1/integrations/inbound/asn?seller_id=01931000-0000-7000-8000-000000000012')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('el calendario de andén de recepción responde por día', function (): void {
    $id = inboundAnnounceViaHttp($this, '01931000-0000-7000-8000-000000000013', '01931001-0000-7000-8000-0000000000e3');
    $this->putJson('/api/v1/integrations/inbound/asn/'.$id.'/schedule', [
        'appointment_date' => '2026-10-20',
        'start_time' => '08:00',
        'end_time' => '12:00',
    ])->assertStatus(202);

    $this->getJson('/api/v1/integrations/inbound/receiving-calendar?from=2026-10-20&days=2&warehouse_id=01931001-0000-7000-8000-0000000000e3')
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-10-20')
        ->assertJsonPath('data.0.appointments', 1);
});

it('el calendario usa hoy y 7 días por defecto cuando faltan parámetros', function (): void {
    $this->getJson('/api/v1/integrations/inbound/receiving-calendar')
        ->assertOk()
        ->assertJsonCount(7, 'data');
});

it('devuelve 404 cuando el ASN no existe', function (): void {
    $this->getJson('/api/v1/integrations/inbound/asn/01931000-0000-7000-8000-0000000000fd')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('traduce una regla de negocio violada a 422 (llegada sin agendar)', function (): void {
    $id = inboundAnnounceViaHttp($this, '01931000-0000-7000-8000-000000000014');

    $this->postJson('/api/v1/integrations/inbound/asn/arrival', ['asn_id' => $id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('rechaza con 422 un anuncio sin líneas (FormRequest)', function (): void {
    $this->postJson('/api/v1/integrations/inbound/asn', [
        'seller_id' => '01931000-0000-7000-8000-000000000015',
        'warehouse_id' => '01931001-0000-7000-8000-0000000000e5',
        'lines' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('lines');
});
