<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\ListNodes;

use Logistics\Network\Application\ReadModel\NodeView;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve todos los nodos como modelos de lectura.
 *
 * @implements QueryHandler<ListNodesQuery, list<NodeView>>
 */
final readonly class ListNodesHandler implements QueryHandler
{
    public function __construct(private NodeRepository $nodes) {}

    /**
     * @return list<NodeView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListNodesQuery);

        return array_map(NodeView::fromNode(...), $this->nodes->all());
    }
}
