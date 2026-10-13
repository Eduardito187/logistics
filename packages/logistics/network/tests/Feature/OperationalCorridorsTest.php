<?php

declare(strict_types=1);

use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Application\Query\OperationalCorridorsByDepartment\OperationalCorridorsByDepartmentQuery;
use Logistics\Network\Application\ReadModel\OperationalCorridorView;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Resuelve el UUID de un nodo ya registrado a partir de su código (el comando de alta no lo devuelve).
 */
function nodeIdByCode(string $code): string
{
    /** @var NodeRepository $nodes */
    $nodes = app(NodeRepository::class);
    $node = $nodes->findByCode(NodeCode::fromString($code));

    return $node?->id()->toString() ?? throw new RuntimeException("nodo no encontrado: {$code}");
}

/**
 * Corredores operativos por departamento, de punta a punta contra PostgreSQL (base logistics_test):
 * se registran nodos con departamento, se define un tramo válido y la consulta devuelve tanto el tramo
 * entre nodos como los corredores terminales que cada nodo ofrece. Se marca skip sin PostgreSQL para no
 * romper la suite Unit.
 */
it('resolves inter-node and terminal corridors for a department', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);

    $commands->dispatch(new RegisterNodeCommand('CD-LP-91', 'CD La Paz', 'distribution_center', -16.51, -68.19, 'la_paz'));
    $commands->dispatch(new RegisterNodeCommand('T-LP-91', 'Tienda La Paz', 'store', -16.49, -68.13, 'la_paz'));
    $commands->dispatch(new RegisterNodeCommand('CD-SCZ-91', 'CD Santa Cruz', 'distribution_center', -17.74, -63.15, 'santa_cruz'));

    $commands->dispatch(new DefineLegCommand(
        fromNodeId: nodeIdByCode('CD-LP-91'),
        toNodeId: nodeIdByCode('T-LP-91'),
        corridorType: 'cd_to_store',
    ));

    /** @var list<OperationalCorridorView> $corridors */
    $corridors = $queries->ask(new OperationalCorridorsByDepartmentQuery('la_paz'));

    $types = array_map(static fn (OperationalCorridorView $c): string => $c->corridorType, $corridors);

    // CD → última milla, tienda → recojo, y el tramo CD → tienda. Nada de Santa Cruz.
    expect($types)->toContain('cd_to_customer')
        ->and($types)->toContain('store_pickup')
        ->and($types)->toContain('cd_to_store')
        ->and($corridors)->each->toBeInstanceOf(OperationalCorridorView::class);

    $interNode = array_values(array_filter($corridors, static fn (OperationalCorridorView $c): bool => $c->corridorType === 'cd_to_store'));
    expect($interNode[0]->originNodeCode)->toBe('CD-LP-91')
        ->and($interNode[0]->destinationNodeCode)->toBe('T-LP-91')
        ->and($interNode[0]->department)->toBe('la_paz');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('rejects a leg whose node types do not match the corridor', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    $commands->dispatch(new RegisterNodeCommand('T-LP-92', 'Tienda A', 'store', -16.49, -68.13, 'la_paz'));
    $commands->dispatch(new RegisterNodeCommand('T-LP-93', 'Tienda B', 'store', -16.50, -68.14, 'la_paz'));

    // cd_to_store exige un CD de origen; dos tiendas no forman ese corredor.
    expect(fn () => $commands->dispatch(new DefineLegCommand(
        fromNodeId: nodeIdByCode('T-LP-92'),
        toNodeId: nodeIdByCode('T-LP-93'),
        corridorType: 'cd_to_store',
    )))->toThrow(NetworkException::class);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
