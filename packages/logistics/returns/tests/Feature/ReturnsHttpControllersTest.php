<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los controllers HTTP de la puerta `integrations` del contexto Returns y de los FormRequest
 * que validan su entrada. Se omite el middleware de puerta (service.auth / idempotency) para ejercitar solo
 * el contrato de los controllers (ApiController: data/accepted/error/guard), sus Request y la cadena CQRS
 * detrás (comandos, consultas, ReadModels, repositorio y handlers) contra logistics_test. No caza bugs:
 * recorre el happy-path real de cada endpoint y sus 404/422/422-de-dominio. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea una devolución directamente en la base (una línea real) para los endpoints que operan sobre una ya
 * existente. Ids UUID v7 únicos para no colisionar con otras colas de la suite.
 */
function returnsHttpSeedRecord(string $status = 'initiated', ?string $ownerPartyId = null, string $mode = 'owned'): ReturnRecord
{
    $record = ReturnRecord::factory()->create([
        'status' => $status,
        'owner_party_id' => $ownerPartyId,
        'fulfillment_mode' => $mode,
        'warehouse_id' => Uuid::generate()->value,
    ]);

    $record->lines()->create([
        'id' => Uuid::generate()->value,
        'sku' => 'BEL-TV-55',
        'quantity' => 2,
        'reason' => 'damaged',
    ]);

    return $record;
}

it('inicia una devolución por el endpoint integrations y responde 202 de acuse', function (): void {
    $response = $this->postJson('/api/v1/integrations/returns', [
        'original_reference' => 'SHP-HTTP-INIT-1',
        'warehouse_id' => Uuid::generate()->value,
        'kind' => 'customer_return',
        'owner_party_id' => Uuid::generate()->value,
        'lines' => [['sku' => 'BEL-TV-55', 'quantity' => 2, 'reason' => 'damaged']],
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.original_reference', 'SHP-HTTP-INIT-1');

    expect(DB::table('returns')->where('original_reference', 'SHP-HTTP-INIT-1')->exists())->toBeTrue();
});

it('inicia una devolución retail (sin dueño) tratando owner_party_id vacío como null', function (): void {
    $this->postJson('/api/v1/integrations/returns', [
        'original_reference' => 'SHP-HTTP-INIT-2',
        'warehouse_id' => Uuid::generate()->value,
        'kind' => 'customer_return',
        'lines' => [['sku' => 'DIS-FRIDGE-300', 'quantity' => 1, 'reason' => 'customer_regret']],
    ])->assertStatus(202);

    expect(DB::table('returns')->where('original_reference', 'SHP-HTTP-INIT-2')->value('owner_party_id'))
        ->toBeNull();
});

it('rechaza iniciar una devolución con cuerpo inválido (422)', function (): void {
    $this->postJson('/api/v1/integrations/returns', [
        'original_reference' => '',
        'warehouse_id' => 'no-es-uuid',
        'kind' => 'otra_cosa',
        'lines' => [],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['original_reference', 'warehouse_id', 'kind', 'lines']);
});

it('devuelve una devolución por su id con la forma de la API', function (): void {
    $record = returnsHttpSeedRecord();

    $this->getJson('/api/v1/integrations/returns/'.$record->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', (string) $record->getKey())
        ->assertJsonPath('data.status', 'initiated')
        ->assertJsonPath('data.lines.0.sku', 'BEL-TV-55');
});

it('responde 404 al pedir una devolución inexistente', function (): void {
    $this->getJson('/api/v1/integrations/returns/'.Uuid::generate()->value)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('lista devoluciones paginadas con envelope data + meta', function (): void {
    returnsHttpSeedRecord();
    returnsHttpSeedRecord();

    $this->getJson('/api/v1/integrations/returns?per_page=1&page=1')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonStructure(['data', 'meta' => ['count', 'page', 'per_page', 'total', 'total_pages']]);
});

it('filtra el listado por seller y estado', function (): void {
    $sellerId = Uuid::generate()->value;
    $record = returnsHttpSeedRecord('received', $sellerId, 'fbd');
    returnsHttpSeedRecord('initiated');

    $response = $this->getJson('/api/v1/integrations/returns?seller_id='.$sellerId.'&status=received')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $response->assertJsonPath('data.0.id', (string) $record->getKey());
});

it('registra la recepción de una devolución iniciada (202) y la deja recibida', function (): void {
    $record = returnsHttpSeedRecord('initiated');

    $this->postJson('/api/v1/integrations/returns/'.$record->getKey().'/receive')
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.return_id', (string) $record->getKey());

    expect(DB::table('returns')->where('id', $record->getKey())->value('status'))->toBe('received');
});

it('dispone restock de una devolución recibida (202) y la deja dispuesta', function (): void {
    $record = returnsHttpSeedRecord('received');

    $this->postJson('/api/v1/integrations/returns/'.$record->getKey().'/disposition', [
        'disposition' => 'restock',
    ])->assertStatus(202)
        ->assertJsonPath('data.return_id', (string) $record->getKey());

    expect(DB::table('returns')->where('id', $record->getKey())->value('disposition'))->toBe('restock');
});

it('rechaza una disposición con valor fuera del catálogo (422)', function (): void {
    $record = returnsHttpSeedRecord('received');

    $this->postJson('/api/v1/integrations/returns/'.$record->getKey().'/disposition', [
        'disposition' => 'incinerar',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['disposition']);
});

it('traduce una regla de negocio violada a 422 (recibir una devolución inexistente)', function (): void {
    $this->postJson('/api/v1/integrations/returns/'.Uuid::generate()->value.'/receive')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});

it('traduce una transición inválida a 422 (disponer una devolución no recibida)', function (): void {
    $record = returnsHttpSeedRecord('initiated');

    $this->postJson('/api/v1/integrations/returns/'.$record->getKey().'/disposition', [
        'disposition' => 'restock',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');
});
