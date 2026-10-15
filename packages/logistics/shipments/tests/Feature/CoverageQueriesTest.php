<?php

declare(strict_types=1);

use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Application\Command\SetDeliverySlot\SetDeliverySlot;
use Logistics\Shipments\Application\Command\SetDeliverySlot\SetDeliverySlotHandler;
use Logistics\Shipments\Application\Ingestion\ImportShipments;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddresses;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddressesHandler;
use Logistics\Shipments\Application\Query\ListShipmentProducts\ListShipmentProducts;
use Logistics\Shipments\Application\Query\ListShipmentProducts\ListShipmentProductsHandler;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\Query\ListShipments\ListShipmentsHandler;
use Logistics\Shipments\Application\ReadModel\ShipmentProductView;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Cobertura de los handlers de consulta/comando y los ReadModels del contexto shipments (Patrón C del
 * brief): se siembran pedidos reales por el caso de uso de ingreso (ReceiveShipment) y se resuelven los
 * handlers del contenedor para asertar la forma del resultado. Contra PostgreSQL (base logistics_test).
 */
function seedReceivedOrder(string $externalId, ?float $lat = -16.5, ?float $lng = -68.15): string
{
    app(CommandBus::class)->dispatch(new ReceiveShipment(
        source: 'STORE',
        externalId: $externalId,
        customerName: 'Cliente Cobertura',
        addressText: 'Av. Siempre Viva 123, La Paz',
        addressReference: 'Puerta azul',
        lat: $lat,
        lng: $lng,
        lines: [
            new ShipmentLineInput('SKU-COB-1', 'Heladera', 2, 'https://example.test/img.png', 4999.90),
            new ShipmentLineInput('SKU-COB-2', 'Microondas', 1, null, null),
        ],
        customerPhone: '+591 70000000',
        customerEmail: 'cobertura@example.test',
    ));

    $shipment = app(ShipmentRepository::class)->findBySourceReference(
        SourceReference::of(OrderSource::Store, $externalId),
    );

    return $shipment?->id()->toString() ?? throw new RuntimeException("envío no encontrado: {$externalId}");
}

it('lista envíos y filtra por estado y fuente', function (): void {
    seedReceivedOrder('ORD-COB-'.uniqid());

    $handler = app(ListShipmentsHandler::class);

    $all = $handler->handle(new ListShipments);
    expect($all)->not->toBeEmpty()
        ->and($all)->each->toBeInstanceOf(ShipmentView::class);

    // Filtrado por estado y fuente: recibidos desde tienda.
    $filtered = $handler->handle(new ListShipments(status: 'RECEIVED', source: 'STORE'));
    expect($filtered)->each->toBeInstanceOf(ShipmentView::class);

    // El ReadModel expone su forma plana en primitivos.
    $array = $all[0]->toArray();
    expect($array)->toHaveKeys(['id', 'source', 'external_id', 'lines'])
        ->and($array['lines'])->toBeArray();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lista los productos de un envío', function (): void {
    $id = seedReceivedOrder('ORD-COB-'.uniqid());

    $products = app(ListShipmentProductsHandler::class)->handle(new ListShipmentProducts($id));

    expect($products)->toHaveCount(2)
        ->and($products)->each->toBeInstanceOf(ShipmentProductView::class)
        ->and($products[0]->toArray())->toHaveKeys(['sku', 'name', 'qty', 'image_url', 'price', 'currency']);

    // Un envío inexistente devuelve lista vacía, no revienta.
    expect(app(ListShipmentProductsHandler::class)->handle(
        new ListShipmentProducts('01920000-0000-7000-8000-000000000000'),
    ))->toBe([]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lista las direcciones de baja confianza', function (): void {
    // Sin coordenada, el scorer deja baja confianza: candidato a confirmar.
    seedReceivedOrder('ORD-COB-'.uniqid(), lat: null, lng: null);

    $low = app(ListLowConfidenceAddressesHandler::class)->handle(new ListLowConfidenceAddresses);

    expect($low)->each->toBeInstanceOf(ShipmentView::class);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('fija la franja de entrega de un envío', function (): void {
    $id = seedReceivedOrder('ORD-COB-'.uniqid());

    app(SetDeliverySlotHandler::class)->handle(new SetDeliverySlot(
        shipmentId: $id,
        deliveryDate: now()->addDay()->toDateString(),
        windowStart: '09:00',
        windowEnd: '12:00',
    ));

    // El slot quedó registrado en el envío recargado por id.
    $shipment = app(ShipmentRepository::class)->findById(ShipmentId::fromString($id));
    expect($shipment)->not->toBeNull();

    $view = ShipmentView::fromShipment($shipment);
    expect($view->deliveryDate)->not->toBeNull()
        ->and($view->deliveryWindowStart)->toBe('09:00')
        ->and($view->deliveryWindowEnd)->toBe('12:00');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('importa pedidos desde una planilla CSV', function (): void {
    $ref = 'CSV-COB-'.uniqid();
    $csv = implode("\n", [
        'source,external_id,customer_name,address_text,address_reference,lat,lng,sku,product_name,quantity',
        "STORE,{$ref},Cliente CSV,Calle Falsa 123 La Paz,Ref,-16.5,-68.15,SKU-CSV-1,Televisor,1",
    ]);

    $report = app(ImportShipments::class)->import($csv);

    expect($report->total())->toBeGreaterThan(0)
        ->and($report->imported + $report->duplicates + $report->failed)->toBe($report->total());
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
