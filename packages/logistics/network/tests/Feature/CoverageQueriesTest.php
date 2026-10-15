<?php

declare(strict_types=1);

use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeCommand;
use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Application\Command\RemoveLeg\RemoveLegCommand;
use Logistics\Network\Application\Query\FindLeg\FindLegHandler;
use Logistics\Network\Application\Query\FindLeg\FindLegQuery;
use Logistics\Network\Application\Query\FindNode\FindNodeHandler;
use Logistics\Network\Application\Query\FindNode\FindNodeQuery;
use Logistics\Network\Application\Query\ListLegs\ListLegsHandler;
use Logistics\Network\Application\Query\ListLegs\ListLegsQuery;
use Logistics\Network\Application\Query\ListNodes\ListNodesHandler;
use Logistics\Network\Application\Query\ListNodes\ListNodesQuery;
use Logistics\Network\Application\ReadModel\LegView;
use Logistics\Network\Application\ReadModel\NodeView;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de los handlers de consulta/comando y los ReadModels del contexto network (Patrón C del
 * brief): se registran nodos y tramos por los comandos de alta y se resuelven los handlers del
 * contenedor para asertar la forma del resultado y los flujos de baja. Contra PostgreSQL (logistics_test).
 */
function coverageNodeId(string $code): string
{
    /** @var NodeRepository $nodes */
    $nodes = app(NodeRepository::class);
    $node = $nodes->findByCode(NodeCode::fromString($code));

    return $node?->id()->toString() ?? throw new RuntimeException("nodo no encontrado: {$code}");
}

it('lista y busca nodos devolviendo ReadModels', function (): void {
    $code = 'CD-Q-'.random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand($code, 'CD Query', 'distribution_center', -16.51, -68.19, 'la_paz'));
    $id = coverageNodeId($code);

    $list = app(ListNodesHandler::class)->handle(new ListNodesQuery);
    expect($list)->not->toBeEmpty()
        ->and($list)->each->toBeInstanceOf(NodeView::class);

    $view = app(FindNodeHandler::class)->handle(new FindNodeQuery($id));
    expect($view)->toBeInstanceOf(NodeView::class)
        ->and($view?->code)->toBe($code)
        ->and($view?->toArray())->toHaveKeys(['id', 'code', 'name', 'type', 'lat', 'lng', 'status', 'department']);

    // Un nodo inexistente devuelve null.
    expect(app(FindNodeHandler::class)->handle(
        new FindNodeQuery('01920000-0000-7000-8000-000000000000'),
    ))->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lista y busca tramos devolviendo ReadModels', function (): void {
    $suffix = random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('CD-Q-'.$suffix, 'CD', 'distribution_center', -16.51, -68.19, 'la_paz'));
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('T-Q-'.$suffix, 'Tienda', 'store', -16.49, -68.13, 'la_paz'));

    app(CommandBus::class)->dispatch(new DefineLegCommand(
        fromNodeId: coverageNodeId('CD-Q-'.$suffix),
        toNodeId: coverageNodeId('T-Q-'.$suffix),
        corridorType: 'cd_to_store',
    ));

    $list = app(ListLegsHandler::class)->handle(new ListLegsQuery);
    expect($list)->not->toBeEmpty()
        ->and($list)->each->toBeInstanceOf(LegView::class);

    /** @var LegRepository $legs */
    $legs = app(LegRepository::class);
    $all = $legs->all();
    $legId = $all[array_key_last($all)]->id()->toString();

    $view = app(FindLegHandler::class)->handle(new FindLegQuery($legId));
    expect($view)->toBeInstanceOf(LegView::class)
        ->and($view?->toArray())->toHaveKeys(['id', 'from_node_id', 'to_node_id', 'corridor_type']);

    expect(app(FindLegHandler::class)->handle(
        new FindLegQuery('01920000-0000-7000-8000-000000000000'),
    ))->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('elimina un tramo y desactiva un nodo por comando', function (): void {
    $suffix = random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('CD-D-'.$suffix, 'CD', 'distribution_center', -16.51, -68.19, 'la_paz'));
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('T-D-'.$suffix, 'Tienda', 'store', -16.49, -68.13, 'la_paz'));

    $cdId = coverageNodeId('CD-D-'.$suffix);
    app(CommandBus::class)->dispatch(new DefineLegCommand(
        fromNodeId: $cdId,
        toNodeId: coverageNodeId('T-D-'.$suffix),
        corridorType: 'cd_to_store',
    ));

    /** @var LegRepository $legs */
    $legs = app(LegRepository::class);
    $before = count($legs->all());
    $legId = $legs->all()[array_key_last($legs->all())]->id()->toString();

    app(CommandBus::class)->dispatch(new RemoveLegCommand($legId));
    expect(count($legs->all()))->toBe($before - 1);

    // El nodo queda desactivado (sin reventar); la consulta sigue devolviéndolo con su estado.
    app(CommandBus::class)->dispatch(new DeactivateNodeCommand($cdId));
    $view = app(FindNodeHandler::class)->handle(new FindNodeQuery($cdId));
    expect($view)->toBeInstanceOf(NodeView::class);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
