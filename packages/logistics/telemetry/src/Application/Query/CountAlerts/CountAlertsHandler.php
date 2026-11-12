<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\CountAlerts;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Domain\Contract\AlertRepository;

/**
 * COUNT(*) de alertas con filtros (una sola query, sin materializar filas).
 *
 * @implements QueryHandler<CountAlerts, int>
 */
final readonly class CountAlertsHandler implements QueryHandler
{
    public function __construct(
        private AlertRepository $alerts,
    ) {}

    public function handle(Query $query): int
    {
        assert($query instanceof CountAlerts);

        return $this->alerts->countBy($query->vehicleId, $query->type, $query->acknowledged, $query->from, $query->to);
    }
}
