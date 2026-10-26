<?php

declare(strict_types=1);

use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Application\Contract\ShipmentListReader;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddresses;
use Logistics\Shipments\Application\Query\ListLowConfidenceAddresses\ListLowConfidenceAddressesHandler;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\Query\ListShipments\ListShipmentsHandler;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\SourceReference;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Falla al sembrar: el envío esperado no apareció en el repositorio tras el ingreso.
 */
final class ShipmentSeedFailure extends RuntimeException {}

/**
 * Lado de LECTURA del listado de envíos (WS1): los filtros se empujan a SQL y el ShipmentView se arma
 * sin reconstruir el agregado. Se verifica el filtrado/paginación del lector y, sobre todo, que su salida
 * sea IDÉNTICA a la del camino histórico (ShipmentView::fromShipment). Contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    // Sin preparación propia: la guarda real es el ->skip() encadenado (requiere PostgreSQL).
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un pedido real por el caso de uso de ingreso (con líneas) y devuelve su id.
 */
function seedReaderOrder(string $externalId, ?float $lat = -16.5, ?float $lng = -68.15): string
{
    app(CommandBus::class)->dispatch(new ReceiveShipment(
        source: 'STORE',
        externalId: $externalId,
        customerName: 'Cliente Lector',
        addressText: 'Av. Siempre Viva 123, La Paz',
        addressReference: 'Puerta azul',
        lat: $lat,
        lng: $lng,
        lines: [
            new ShipmentLineInput('SKU-RD-1', 'Heladera', 2, 'https://example.test/img.png', 4999.90),
            new ShipmentLineInput('SKU-RD-2', 'Microondas', 1, null, null),
        ],
        customerPhone: '+591 70000000',
        customerEmail: 'lector@example.test',
    ));

    $shipment = app(ShipmentRepository::class)->findBySourceReference(
        SourceReference::of(OrderSource::Store, $externalId),
    );

    return $shipment?->id()->toString() ?? throw new ShipmentSeedFailure("envío no encontrado: {$externalId}");
}

it('produce exactamente la misma vista que fromShipment (paridad de contrato)', function (): void {
    $id = seedReaderOrder('RD-PAR-'.uniqid());

    // Camino histórico: agregado → ShipmentView::fromShipment.
    $shipment = app(ShipmentRepository::class)->findById(
        ShipmentId::fromString($id),
    );
    $expected = ShipmentView::fromShipment($shipment)->toArray();

    // Camino nuevo: lector SQL.
    $reader = app(ShipmentListReader::class);
    $fromReader = collect($reader->list())
        ->first(fn (ShipmentView $view): bool => $view->id === $id);

    expect($fromReader)->not->toBeNull()
        ->and($fromReader->toArray())->toEqual($expected);
});

it('empuja los filtros por estado, fuente y nodo destino a SQL', function (): void {
    $nodeA = Uuid::generate()->value;
    $nodeB = Uuid::generate()->value;

    ShipmentRecord::factory()->forNode($nodeA)->create(['source' => OrderSource::Store->value]);
    ShipmentRecord::factory()->forNode($nodeA)->create(['source' => OrderSource::Store->value]);
    ShipmentRecord::factory()->forNode($nodeB)->create(['source' => OrderSource::Store->value]);

    $reader = app(ShipmentListReader::class);

    $forNodeA = $reader->list(destinationNodeId: $nodeA);
    expect($forNodeA)->toHaveCount(2);
    foreach ($forNodeA as $view) {
        expect($view->destinationNodeId)->toBe($nodeA);
    }

    // Estado inexistente: nada.
    expect($reader->list(status: 'DELIVERED', destinationNodeId: $nodeA))->toBe([]);
});

it('acota el resultado con limit y offset en SQL', function (): void {
    $node = Uuid::generate()->value;
    for ($i = 0; $i < 5; $i++) {
        ShipmentRecord::factory()->forNode($node)->create();
    }

    $reader = app(ShipmentListReader::class);

    $all = $reader->list(destinationNodeId: $node);
    expect($all)->toHaveCount(5);

    $page = $reader->list(destinationNodeId: $node, limit: 2, offset: 2);
    expect($page)->toHaveCount(2)
        // Orden estable por id: la página coincide con el tramo del listado completo.
        ->and($page[0]->id)->toBe($all[2]->id)
        ->and($page[1]->id)->toBe($all[3]->id);
});

it('filtra las direcciones de confianza baja en SQL', function (): void {
    // Sin coordenada el scorer deja confianza baja (candidata a confirmar).
    $lowId = seedReaderOrder('RD-LOW-'.uniqid(), lat: null, lng: null);
    // Con coordenada queda confianza alta.
    seedReaderOrder('RD-HIGH-'.uniqid());

    $low = app(ShipmentListReader::class)->list(lowConfidenceOnly: true);

    $ids = array_map(static fn (ShipmentView $view): string => $view->id, $low);
    expect($ids)->toContain($lowId);
    foreach ($low as $view) {
        expect($view->addressConfidenceLevel)->toBe('LOW');
    }
});

it('los handlers de consulta usan el lector y preservan la forma', function (): void {
    seedReaderOrder('RD-H-'.uniqid());

    $all = app(ListShipmentsHandler::class)->handle(new ListShipments);
    expect($all)->not->toBeEmpty()
        ->and($all)->each->toBeInstanceOf(ShipmentView::class);

    $filtered = app(ListShipmentsHandler::class)->handle(new ListShipments(status: 'RECEIVED', source: 'STORE'));
    expect($filtered)->each->toBeInstanceOf(ShipmentView::class);
    foreach ($filtered as $view) {
        expect($view->status)->toBe('RECEIVED')
            ->and($view->source)->toBe('STORE');
    }

    $low = app(ListLowConfidenceAddressesHandler::class)->handle(new ListLowConfidenceAddresses);
    expect($low)->each->toBeInstanceOf(ShipmentView::class);
});

it('rechaza un estado inválido igual que antes (validación por enum)', function (): void {
    expect(fn (): array => app(ListShipmentsHandler::class)->handle(new ListShipments(status: 'NOPE')))
        ->toThrow(ValueError::class);
});
