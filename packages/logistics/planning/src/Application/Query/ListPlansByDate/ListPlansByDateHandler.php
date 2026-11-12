<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\ListPlansByDate;

use DateInterval;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve los planes de un día (o de un rango acotado de días) como modelos de lectura.
 *
 * Volumen: un plan por camión y por día, así que incluso un rango de 31 días son a lo sumo unos pocos
 * cientos de planes; filtrar y paginar en memoria sobre lo que devuelve el repositorio es aceptable y no
 * obliga a cambiar el contrato de persistencia. Orden estable: día ascendente y, dentro del día, el orden
 * del repositorio (por vehículo).
 *
 * @implements QueryHandler<ListPlansByDate, list<PlanView>|Page<PlanView>>
 */
final readonly class ListPlansByDateHandler implements QueryHandler
{
    public function __construct(private PlanRepository $plans) {}

    /**
     * @return list<PlanView>|Page<PlanView>
     */
    public function handle(Query $query): array | Page
    {
        if (!$query instanceof ListPlansByDate) {
            throw PlanningException::because('ListPlansByDateHandler solo maneja ListPlansByDate');
        }

        $plans = [];
        foreach ($this->days($query) as $day) {
            foreach ($this->plans->findByDate($day) as $plan) {
                $plans[] = $plan;
            }
        }

        if ($query->status !== null) {
            $plans = array_values(array_filter(
                $plans,
                static fn (DeliveryPlan $plan): bool => $plan->status()->value === $query->status,
            ));
        }

        if ($query->vehicleId !== null) {
            $plans = array_values(array_filter(
                $plans,
                static fn (DeliveryPlan $plan): bool => $plan->vehicleId() === $query->vehicleId,
            ));
        }

        // Sin página pedida: comportamiento histórico (lista completa del filtro).
        if ($query->limit === null) {
            return array_map(PlanView::fromPlan(...), $plans);
        }

        $slice = \array_slice($plans, max(0, $query->offset), max(1, $query->limit));

        return new Page(array_map(PlanView::fromPlan(...), $slice), \count($plans));
    }

    /**
     * Días a consultar: solo `planDate`, o de `planDate` a `dateTo` inclusive (máximo MAX_RANGE_DAYS).
     *
     * @return list<PlanDate>
     */
    private function days(ListPlansByDate $query): array
    {
        $from = PlanDate::fromString($query->planDate);

        if ($query->dateTo === null) {
            return [$from];
        }

        $to = PlanDate::fromString($query->dateTo);
        if ($to->value < $from->value) {
            throw PlanningException::because('el fin del rango no puede ser anterior al inicio');
        }

        $span = (int) $from->value->diff($to->value)->days + 1;
        if ($span > ListPlansByDate::MAX_RANGE_DAYS) {
            throw PlanningException::because(
                'el rango de fechas no puede superar '.ListPlansByDate::MAX_RANGE_DAYS.' días',
            );
        }

        $days = [];
        $cursor = $from->value;
        for ($i = 0; $i < $span; $i++) {
            $days[] = PlanDate::of($cursor);
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        return $days;
    }
}
