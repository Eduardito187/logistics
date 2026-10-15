<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\ListLegs;

use Logistics\Network\Application\ReadModel\LegView;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve todos los tramos como modelos de lectura.
 *
 * @implements QueryHandler<ListLegsQuery, list<LegView>>
 */
final readonly class ListLegsHandler implements QueryHandler
{
    public function __construct(private LegRepository $legs) {}

    /**
     * @return list<LegView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListLegsQuery);

        return array_map(LegView::fromLeg(...), $this->legs->all());
    }
}
