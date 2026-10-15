<?php

declare(strict_types=1);

use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;

/**
 * Cobertura de los controllers HTTP del conector SAP (patrón F del brief W2): las puertas app e
 * integrations. Se omite el middleware de puerta (service.auth / auth:sanctum / device / idempotency)
 * para ejercitar solo el contrato de los controllers, sus FormRequest y la cadena CQRS detrás
 * (comandos, consultas, ReadModels, repositorios y handlers, incluido AcknowledgeStockTransferHandler).
 * No caza bugs: recorre el happy-path real de cada endpoint y algún 404/422 contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('define y lista el mapa de materiales por sus endpoints de la puerta app', function (): void {
    // POST: define un mapeo (DefineMaterialMappingRequest + DefineMaterialMapping) → 201.
    $this->postJson('/api/v1/app/sap/material-mappings', [
        'sku' => 'SKU-HTTP-1',
        'material' => '770001',
        'center' => 'C7001',
        'warehouse' => 'W70',
    ])->assertCreated()
        ->assertJsonPath('data.sku', 'SKU-HTTP-1')
        ->assertJsonPath('meta.message', 'Material mapping defined.');

    // GET: lista el mapa completo (ListMaterialMappings → MaterialMappingView::toArray).
    $this->getJson('/api/v1/app/sap/material-mappings')
        ->assertOk()
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.sku', 'SKU-HTTP-1');
});

it('rechaza un mapeo de material sin campos requeridos con 422', function (): void {
    $this->postJson('/api/v1/app/sap/material-mappings', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sku', 'material', 'center', 'warehouse']);
});

it('encola un traspaso de stock con payload válido y responde 202 ENQUEUED', function (): void {
    MaterialMappingRecord::factory()->create([
        'sku' => 'SKU-HTTP-ENQ',
        'sap_material' => '880001',
        'center' => 'C8001',
        'warehouse' => 'W80',
    ]);

    $this->postJson('/api/v1/app/sap/stock-transfers', [
        'event_id' => '01a13700-0000-7000-8000-000000009001',
        'shipment_ref' => 'SHP-HTTP-1',
        'sku' => 'SKU-HTTP-ENQ',
        'quantity' => 4,
    ])->assertStatus(202)
        ->assertJsonPath('data.status', 'ENQUEUED')
        ->assertJsonPath('data.shipment_ref', 'SHP-HTTP-1');

    expect(StockTransferRecord::query()->where('event_id', '01a13700-0000-7000-8000-000000009001')->exists())
        ->toBeTrue();
});

it('rechaza encolar un traspaso con cantidad inválida con 422', function (): void {
    $this->postJson('/api/v1/app/sap/stock-transfers', [
        'event_id' => 'EVT-X',
        'shipment_ref' => 'SHP-X',
        'sku' => 'SKU-X',
        'quantity' => 0,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

it('lista los traspasos pendientes y fallidos de reconciliación por la puerta app', function (): void {
    StockTransferRecord::factory()->pending()->create();
    StockTransferRecord::factory()->failed()->create();
    StockTransferRecord::factory()->acknowledged()->create();

    // Pendientes = Pending + Failed (reconciliación accionable).
    $this->getJson('/api/v1/app/sap/stock-transfers/pending')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['count']]);

    $this->getJson('/api/v1/app/sap/stock-transfers/failed')
        ->assertOk()
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.status', 'FAILED');
});

it('dispara una corrida de despacho de pendientes por la puerta app y responde 202', function (): void {
    StockTransferRecord::factory()->pending()->create();

    $this->postJson('/api/v1/app/sap/stock-transfers/dispatch')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'DISPATCH_REQUESTED');
});

it('importa pedidos de SAP por la puerta integrations (stub Null: sin pedidos)', function (): void {
    // Con el NullSapClient por defecto fetchOrders() devuelve [], así que el controller responde 200
    // con count 0, ejercitando el envelope y el mapeo de ImportedOrderDto.
    $this->postJson('/api/v1/integrations/sap/orders/import')
        ->assertOk()
        ->assertJsonPath('meta.count', 0);
});

it('importa pedidos de SAP traduciendo material a sku con un puerto falso', function (): void {
    MaterialMappingRecord::factory()->create([
        'sku' => 'SKU-HTTP-IMP',
        'sap_material' => '990001',
        'center' => 'C9001',
        'warehouse' => 'W90',
    ]);

    // Puerto SAP falso en memoria que devuelve un pedido crudo: recorre la traducción del handler.
    $fakeSap = new class implements SapClient
    {
        public function pushStockTransfer(StockTransfer $transfer): void {}

        public function fetchOrders(): array
        {
            return [[
                'order_ref' => 'SAP-HTTP-1',
                'customer_name' => 'Cliente HTTP',
                'address_text' => 'Calle Falsa 123',
                'address_reference' => 'Portón rojo',
                'lines' => [[
                    'material' => '990001',
                    'description' => 'Lavadora',
                    'quantity' => 1,
                ]],
            ]];
        }
    };
    app()->instance(SapClient::class, $fakeSap);

    $this->postJson('/api/v1/integrations/sap/orders/import')
        ->assertOk()
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.external_id', 'SAP-HTTP-1');
});

it('confirma un traspaso enviado por el callback de SAP de la puerta integrations', function (): void {
    $record = StockTransferRecord::factory()->sent()->create([
        'event_id' => '01a13700-0000-7000-8000-00000000ac01',
    ]);

    $this->postJson('/api/v1/integrations/sap/stock-transfers/acknowledge', [
        'event_id' => $record->event_id,
    ])->assertOk()
        ->assertJsonPath('data.status', 'ACKNOWLEDGED');

    expect(StockTransferRecord::query()->where('event_id', $record->event_id)->value('status'))
        ->toBe('ACKNOWLEDGED');
});

it('rechaza el callback de confirmación sin event_id con 422', function (): void {
    $this->postJson('/api/v1/integrations/sap/stock-transfers/acknowledge', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['event_id']);
});
