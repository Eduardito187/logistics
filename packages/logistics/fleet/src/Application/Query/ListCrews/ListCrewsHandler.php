<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListCrews;

use Logistics\Fleet\Application\Contract\CrewDirectory;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @phpstan-import-type CrewRow from CrewDirectory
 *
 * @implements QueryHandler<ListCrews, array{items: list<CrewRow>, total: int}>
 */
final readonly class ListCrewsHandler implements QueryHandler
{
    public function __construct(
        private CrewDirectory $crews,
    ) {}

    /**
     * @return array{items: list<CrewRow>, total: int}
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListCrews);

        return $this->crews->list($query->date, $query->vehicleId, $query->driverId, $query->limit, $query->offset);
    }
}
