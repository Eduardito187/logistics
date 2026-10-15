<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Query\GetOutboxStats;

use Logistics\Outbox\Application\ReadModel\OutboxStatsView;
use Logistics\Outbox\Domain\Contract\OutboxReader;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el conteo de mensajes por estado como ReadModel.
 *
 * @implements QueryHandler<GetOutboxStatsQuery, OutboxStatsView>
 */
final readonly class GetOutboxStatsHandler implements QueryHandler
{
    public function __construct(private OutboxReader $reader) {}

    public function handle(Query $query): OutboxStatsView
    {
        assert($query instanceof GetOutboxStatsQuery);

        return OutboxStatsView::fromCounts($this->reader->countByStatus());
    }
}
