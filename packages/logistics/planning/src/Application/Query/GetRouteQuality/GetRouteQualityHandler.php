<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\GetRouteQuality;

use Logistics\Planning\Application\ReadModel\RouteQualityView;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteLoadProfileProvider;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\PlannedStop;
use Logistics\Planning\Domain\Service\RouteQualityScorer;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve las métricas de calidad de un plan (R-06): carga el plan (distancia/tiempo salen de su geometría
 * persistida), lee la carga de sus envíos y la capacidad de su camión por la puerta de lectura (read-only,
 * en lote) y arma la vista con la ocupación calculada. Devuelve null si el plan no existe.
 *
 * @implements QueryHandler<GetRouteQuality, ?RouteQualityView>
 */
final readonly class GetRouteQualityHandler implements QueryHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RouteLoadProfileProvider $loadProfiles,
        private RouteQualityScorer $scorer,
    ) {}

    public function handle(Query $query): ?RouteQualityView
    {
        if (!$query instanceof GetRouteQuality) {
            throw PlanningException::because('GetRouteQualityHandler solo maneja GetRouteQuality');
        }

        $plan = $this->plans->findById(PlanId::fromString($query->planId));
        if ($plan === null) {
            return null;
        }

        $shipmentIds = array_map(
            static fn (PlannedStop $stop): string => $stop->shipmentId(),
            $plan->stops(),
        );

        $load = $this->loadProfiles->loadFor($shipmentIds);
        $capacity = $this->loadProfiles->capacityFor($plan->vehicleId());

        return RouteQualityView::fromPlan(
            $plan,
            $this->scorer,
            $load['kg'],
            $load['m3'],
            $capacity['kg'] ?? null,
            $capacity['m3'] ?? null,
        );
    }
}
