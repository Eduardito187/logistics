<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\ListDeliveredAwaitingRating;

use DateInterval;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\Contract\RatingRequestStore;
use Logistics\Tracking\Application\ReadModel\DeliveredAwaitingRatingView;
use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Resuelve los candidatos a invitación de calificación: fija el corte superior (`ahora − minAgeMinutes`)
 * con el reloj y delega la lectura al store del read model. Un `since` posterior al corte devuelve vacío.
 *
 * @implements QueryHandler<ListDeliveredAwaitingRating, list<DeliveredAwaitingRatingView>>
 */
final class ListDeliveredAwaitingRatingHandler implements QueryHandler
{
    public function __construct(
        private readonly RatingRequestStore $store,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<DeliveredAwaitingRatingView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListDeliveredAwaitingRating) {
            throw TrackingException::because('ListDeliveredAwaitingRatingHandler solo maneja ListDeliveredAwaitingRating');
        }

        $limit = max(1, min($query->limit, ListDeliveredAwaitingRating::MAX_LIMIT));
        $minAge = max(0, $query->minAgeMinutes);
        $deliveredBefore = $this->clock->now()->sub(new DateInterval('PT'.$minAge.'M'));

        if ($query->since > $deliveredBefore) {
            return [];
        }

        return $this->store->awaitingRating($query->since, $deliveredBefore, $limit);
    }
}
