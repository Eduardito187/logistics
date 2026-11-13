<?php

declare(strict_types=1);

use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Application\Command\RegisterReceivingDock\RegisterReceivingDockCommand;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Infrastructure\Persistence\Eloquent\LegRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\ReceivingDockRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de las relaciones Eloquent de los records del contexto network, que el admin usa para
 * mostrar el almacén/nodos de un andén o tramo sin exponer UUIDs. Se crean los datos por los comandos
 * de alta y se recorren las relaciones sobre los records reales. Contra PostgreSQL (base logistics_test).
 */

/**
 * Resuelve el UUID de un nodo ya registrado a partir de su código.
 */
function networkRecordsNodeId(string $code): string
{
    /** @var NodeRepository $nodes */
    $nodes = app(NodeRepository::class);
    $node = $nodes->findByCode(NodeCode::fromString($code));

    return $node?->id()->toString() ?? throw new RuntimeException("nodo no encontrado: {$code}");
}

it('resolves the warehouse of a receiving dock through its relation', function (): void {
    $code = 'AC-REC-'.random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand($code, 'Central', 'central_warehouse', -16.5, -68.15, 'la_paz'));
    $warehouseId = networkRecordsNodeId($code);

    app(CommandBus::class)->dispatch(new RegisterReceivingDockCommand($warehouseId, 'DOCK-REC', 'North dock', 3));

    $dock = ReceivingDockRecord::query()->where('warehouse_id', $warehouseId)->firstOrFail();
    $warehouse = $dock->warehouse;

    expect($warehouse)->toBeInstanceOf(NodeRecord::class)
        ->and($warehouse?->code)->toBe($code);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('resolves the origin and destination nodes of a leg and its legs from each node', function (): void {
    $suffix = random_int(1000, 9999);
    $cdCode = 'CD-REC-'.$suffix;
    $storeCode = 'T-REC-'.$suffix;
    app(CommandBus::class)->dispatch(new RegisterNodeCommand($cdCode, 'CD', 'distribution_center', -16.51, -68.19, 'la_paz'));
    app(CommandBus::class)->dispatch(new RegisterNodeCommand($storeCode, 'Tienda', 'store', -16.49, -68.13, 'la_paz'));

    $fromId = networkRecordsNodeId($cdCode);
    $toId = networkRecordsNodeId($storeCode);
    app(CommandBus::class)->dispatch(new DefineLegCommand(
        fromNodeId: $fromId,
        toNodeId: $toId,
        corridorType: 'cd_to_store',
    ));

    $leg = LegRecord::query()->where('from_node_id', $fromId)->where('to_node_id', $toId)->firstOrFail();

    expect($leg->fromNode?->code)->toBe($cdCode)
        ->and($leg->toNode?->code)->toBe($storeCode);

    $cd = NodeRecord::query()->findOrFail($fromId);
    $store = NodeRecord::query()->findOrFail($toId);

    expect($cd->outgoingLegs->pluck('id')->all())->toContain($leg->id)
        ->and($store->incomingLegs->pluck('id')->all())->toContain($leg->id);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
