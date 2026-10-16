<?php

declare(strict_types=1);

use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Application\Command\RegisterReceivingDock\RegisterReceivingDockCommand;
use Logistics\Network\Application\Query\ListReceivingDocks\ListReceivingDocksQuery;
use Logistics\Network\Application\ReadModel\ReceivingDockView;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Andenes de recepción de punta a punta contra PostgreSQL (base logistics_test): se registra un almacén
 * central, se le cuelgan andenes vía CommandBus y la consulta los devuelve como ReadModels. También se
 * verifica que un andén no pueda colgar de un nodo que no recibe de proveedores. Skip sin PostgreSQL.
 */
function warehouseIdByCode(string $code): string
{
    /** @var NodeRepository $nodes */
    $nodes = app(NodeRepository::class);
    $node = $nodes->findByCode(NodeCode::fromString($code));

    return $node?->id()->toString() ?? throw new RuntimeException("nodo no encontrado: {$code}");
}

it('registers receiving docks on a central warehouse and lists them', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);

    $commands->dispatch(new RegisterNodeCommand('AC-LP-01', 'Central La Paz', 'central_warehouse', -16.5, -68.15, 'la_paz'));
    $warehouseId = warehouseIdByCode('AC-LP-01');

    $commands->dispatch(new RegisterReceivingDockCommand($warehouseId, 'DOCK-02', 'South dock', 3));
    $commands->dispatch(new RegisterReceivingDockCommand($warehouseId, 'DOCK-01', 'North dock', 5));

    /** @var list<ReceivingDockView> $docks */
    $docks = $queries->ask(new ListReceivingDocksQuery($warehouseId));

    expect($docks)->toHaveCount(2)
        // Ordenados por código.
        ->and($docks[0]->code)->toBe('DOCK-01')
        ->and($docks[0]->appointmentsPerSlot)->toBe(5)
        ->and($docks[0]->active)->toBeTrue()
        ->and($docks[1]->code)->toBe('DOCK-02')
        ->and($docks[0]->toArray())->toHaveKeys(['id', 'warehouse_id', 'code', 'name', 'appointments_per_slot', 'active']);
});

it('rejects a duplicate dock code within the same warehouse', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    $commands->dispatch(new RegisterNodeCommand('AC-CB-01', 'Central Cochabamba', 'central_warehouse', -17.39, -66.15, 'cochabamba'));
    $warehouseId = warehouseIdByCode('AC-CB-01');

    $commands->dispatch(new RegisterReceivingDockCommand($warehouseId, 'A1', 'Dock A1', 2));

    expect(fn () => $commands->dispatch(new RegisterReceivingDockCommand($warehouseId, 'A1', 'Dock A1 dup', 2)))
        ->toThrow(NetworkException::class);
});

it('rejects a dock on a node that does not receive inbound', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    $commands->dispatch(new RegisterNodeCommand('CD-SC-01', 'CD Santa Cruz', 'distribution_center', -17.78, -63.18, 'santa_cruz'));
    $warehouseId = warehouseIdByCode('CD-SC-01');

    expect(fn () => $commands->dispatch(new RegisterReceivingDockCommand($warehouseId, 'B1', 'Dock B1', 2)))
        ->toThrow(NetworkException::class);
});
