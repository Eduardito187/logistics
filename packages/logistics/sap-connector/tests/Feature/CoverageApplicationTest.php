<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Logistics\SapConnector\Application\Command\DefineMaterialMapping\DefineMaterialMapping;
use Logistics\SapConnector\Application\Command\DefineMaterialMapping\DefineMaterialMappingHandler;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrders;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrdersHandler;
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfers;
use Logistics\SapConnector\Application\Query\ListMaterialMappings\ListMaterialMappings;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfers;
use Logistics\SapConnector\Application\ReadModel\MaterialMappingView;
use Logistics\SapConnector\Application\ReadModel\StockTransferView;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;
use Logistics\SapConnector\Infrastructure\Sap\HttpSapClient;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura de la capa de Aplicación del conector SAP (patrón C): consultas de reconciliación por el
 * QueryBus, comando de definición de mapeo por el CommandBus, importación de pedidos con un puerto SAP
 * falso en memoria y el cliente HTTP con Http::fake. Nada de I/O real contra SAP.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('lista los mapeos de material como read models', function (): void {
    MaterialMappingRecord::factory()->count(2)->create();

    $views = app(QueryBus::class)->ask(new ListMaterialMappings);

    expect($views)->toBeArray()->toHaveCount(2)
        ->and($views[0])->toBeInstanceOf(MaterialMappingView::class)
        ->and($views[0]->toArray())->toHaveKeys(['id', 'sku', 'material', 'center', 'warehouse']);
});

it('lista los traspasos pendientes y fallidos de reconciliación', function (): void {
    StockTransferRecord::factory()->pending()->create();
    StockTransferRecord::factory()->failed()->create();
    StockTransferRecord::factory()->acknowledged()->create();

    $pending = app(QueryBus::class)->ask(new ListPendingTransfers);
    $failed = app(QueryBus::class)->ask(new ListFailedTransfers);

    expect($pending)->toBeArray()
        ->and($pending[0])->toBeInstanceOf(StockTransferView::class)
        ->and($failed)->toBeArray()->toHaveCount(1)
        ->and($failed[0]->status)->toBe('FAILED');
});

it('define un mapeo de material y es idempotente por sku (actualiza la coordenada)', function (): void {
    app(CommandBus::class)->dispatch(new DefineMaterialMapping('SKU-COV-1', '900111', 'C9001', 'W90'));

    expect(MaterialMappingRecord::query()->where('sku', 'SKU-COV-1')->value('sap_material'))->toBe('900111');

    // El handler directo expone lastMappingId() y conserva la identidad al redefinir el mismo sku.
    $handler = app(DefineMaterialMappingHandler::class);
    $handler->handle(new DefineMaterialMapping('SKU-COV-1', '900222', 'C9002', 'W91'));
    $firstId = $handler->lastMappingId()->toString();
    $handler->handle(new DefineMaterialMapping('SKU-COV-1', '900333', 'C9003', 'W92'));

    expect($handler->lastMappingId()->toString())->toBe($firstId)
        ->and(MaterialMappingRecord::query()->where('sku', 'SKU-COV-1')->count())->toBe(1)
        ->and(MaterialMappingRecord::query()->where('sku', 'SKU-COV-1')->value('sap_material'))->toBe('900333');
});

it('importa pedidos de SAP traduciendo material a sku propio', function (): void {
    MaterialMappingRecord::factory()->create([
        'sku' => 'SKU-IMPORT-1',
        'sap_material' => '555001',
        'center' => 'C5001',
        'warehouse' => 'W50',
    ]);

    // Puerto SAP falso en memoria: devuelve un pedido crudo para ejercitar la traducción y los DTOs.
    $fakeSap = new class implements SapClient
    {
        public function pushStockTransfer(StockTransfer $transfer): void {}

        public function fetchOrders(): array
        {
            return [[
                'order_ref' => 'SAP-9001',
                'customer_name' => 'Cliente Demo',
                'address_text' => 'Av. Siempre Viva 123',
                'address_reference' => 'Casa azul',
                'lines' => [[
                    'material' => '555001',
                    'description' => 'Refrigerador',
                    'quantity' => 2,
                ]],
            ]];
        }
    };
    app()->instance(SapClient::class, $fakeSap);

    $handler = app(ImportSapOrdersHandler::class);
    $handler->handle(new ImportSapOrders);
    $orders = $handler->importedOrders();

    expect($orders)->toHaveCount(1)
        ->and($orders[0]->externalId)->toBe('SAP-9001')
        ->and($orders[0]->lines[0]->sku)->toBe('SKU-IMPORT-1')
        ->and($orders[0]->toArray()['lines'][0]['quantity'])->toBe(2);
});

it('empuja un traspaso a SAP por HTTP (Http::fake) y lee pedidos', function (): void {
    Http::fake([
        '*/stock-transfers' => Http::response('', 201),
        '*/orders/pending' => Http::response(['orders' => [['order_ref' => 'X', 'lines' => []]]], 200),
    ]);

    $client = new HttpSapClient(
        app(Factory::class),
        'http://sap.test',
        'svc',
        'secret',
        '/orders/pending',
        '/stock-transfers',
        5,
    );

    $transfer = StockTransfer::fromReception(
        StockTransferId::generate(),
        'REC-COV-1',
        'SHP-COV-1',
        SapMaterial::of('700001'),
        SapCenter::of('C7001'),
        SapWarehouse::of('W70'),
        4,
    );

    $client->pushStockTransfer($transfer);
    $orders = $client->fetchOrders();

    expect($orders)->toBeArray()->toHaveCount(1);
    Http::assertSentCount(2);
});

it('corre el worker de reconciliación por consola sin colgarse', function (): void {
    StockTransferRecord::factory()->pending()->create();

    $this->artisan('logistics:sap:dispatch-transfers')->assertSuccessful();
});
