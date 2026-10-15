<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetActiveHorizon;

use Logistics\Capacity\Application\ReadModel\ActiveHorizonView;
use Logistics\Capacity\Domain\Contract\ActiveHorizonRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el horizonte activo de cupos de un alcance como modelo de lectura, o null si no hay.
 *
 * @implements QueryHandler<GetActiveHorizonQuery, ActiveHorizonView|null>
 */
final readonly class GetActiveHorizonHandler implements QueryHandler
{
    public function __construct(private ActiveHorizonRepository $horizons) {}

    public function handle(Query $query): ?ActiveHorizonView
    {
        assert($query instanceof GetActiveHorizonQuery);

        $horizon = $this->horizons->findFor($query->municipalityId);

        return $horizon === null ? null : ActiveHorizonView::fromDomain($horizon);
    }
}
