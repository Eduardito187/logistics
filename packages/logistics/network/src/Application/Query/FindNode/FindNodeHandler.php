<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\FindNode;

use Logistics\Network\Application\ReadModel\NodeView;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el modelo de lectura de un nodo, o null si no existe.
 *
 * @implements QueryHandler<FindNodeQuery, NodeView|null>
 */
final readonly class FindNodeHandler implements QueryHandler
{
    public function __construct(private NodeRepository $nodes) {}

    public function handle(Query $query): ?NodeView
    {
        assert($query instanceof FindNodeQuery);

        $node = $this->nodes->findById(NodeId::fromString($query->nodeId));

        return $node === null ? null : NodeView::fromNode($node);
    }
}
