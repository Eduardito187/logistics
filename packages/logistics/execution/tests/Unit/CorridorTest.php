<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\NodeKind;

it('derives the corridor from the origin and destination node kinds', function (NodeKind $origin, NodeKind $destination, Corridor $expected): void {
    expect(Corridor::between($origin, $destination))->toBe($expected);
})->with([
    'CD to CD' => [NodeKind::Cd, NodeKind::Cd, Corridor::CdToCd],
    'CD to store' => [NodeKind::Cd, NodeKind::Store, Corridor::CdToStore],
    'store to store' => [NodeKind::Store, NodeKind::Store, Corridor::StoreToStore],
    'store to CD' => [NodeKind::Store, NodeKind::Cd, Corridor::StoreToCd],
]);

it('parses a node kind code case-insensitively', function (): void {
    expect(NodeKind::fromCode('cd'))->toBe(NodeKind::Cd)
        ->and(NodeKind::fromCode(' STORE '))->toBe(NodeKind::Store);
});

it('rejects an unknown node kind code', function (): void {
    expect(fn () => NodeKind::fromCode('WAREHOUSE'))
        ->toThrow(ExecutionException::class, 'tipo de nodo desconocido');
});
