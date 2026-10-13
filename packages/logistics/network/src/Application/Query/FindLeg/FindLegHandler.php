<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\FindLeg;

use Logistics\Network\Application\ReadModel\LegView;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el modelo de lectura de un tramo, o null si no existe.
 *
 * @implements QueryHandler<FindLegQuery, LegView|null>
 */
final readonly class FindLegHandler implements QueryHandler
{
    public function __construct(private LegRepository $legs) {}

    public function handle(Query $query): ?LegView
    {
        assert($query instanceof FindLegQuery);

        $leg = $this->legs->findById(LegId::fromString($query->legId));

        return $leg === null ? null : LegView::fromLeg($leg);
    }
}
