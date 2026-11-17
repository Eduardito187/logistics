<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\ListPlansByDate;

use DateInterval;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Application\Service\PlanViewAssembler;
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
 * del repositorio (por vehículo). Placa y chofer de cada plan se resuelven en lote (PlanViewAssembler).
 *
 * @implements QueryHandler<ListPlansByDate, list<PlanView>|Page<PlanView>>
 */
final readonly class ListPlansByDateHandler implements QueryHandler
{
    public function __construct(
        private PlanRepository $plans,
        private PlanViewAssembler $views,
    ) {}

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

        if ($query->statuses !== []) {
            $plans = array_values(array_filter(
                $plans,
                static fn (DeliveryPlan $plan): bool => in_array($plan->status()->value, $query->statuses, true),
            ));
        }

        if ($query->vehicleId !== null) {
            $plans = array_values(array_filter(
                $plans,
                static fn (DeliveryPlan $plan): bool => $plan->vehicleId() === $query->vehicleId,
            ));
        }

        $search = $query->search !== null ? trim($query->search) : '';

        // Filtro de texto (`q`): necesita los datos HUMANOS ya resueltos (placa, chofer, nodo de origen), así
        // que se arma la vista de TODO el conjunto filtrado y se filtra sobre ella antes de paginar.
        if ($search !== '') {
            $views = $this->filterBySearch($this->views->assemble($plans), $search);
            if ($query->limit === null) {
                return $views;
            }

            return new Page(
                array_values(\array_slice($views, max(0, $query->offset), max(1, $query->limit))),
                \count($views),
            );
        }

        // Sin página pedida: comportamiento histórico (lista completa del filtro).
        if ($query->limit === null) {
            return $this->views->assemble($plans);
        }

        $slice = \array_slice($plans, max(0, $query->offset), max(1, $query->limit));

        // Placa y chofer se resuelven en lote SOLO para la página devuelta.
        return new Page($this->views->assemble($slice), \count($plans));
    }

    /**
     * Filtra los planes por texto libre contra la placa del camión, el nombre del chofer, el nombre del nodo
     * de origen y —por parada— el número de pedido y el nombre del cliente (el dueño busca mucho por
     * pedido/cliente). Inclusión de substring, caja indiferente.
     *
     * @param  list<PlanView> $views
     * @return list<PlanView>
     */
    private function filterBySearch(array $views, string $search): array
    {
        $needle = mb_strtolower($search);

        return array_values(array_filter($views, static function (PlanView $view) use ($needle): bool {
            $parts = [
                $view->plate,
                $view->driverName,
                is_array($view->originNode) && isset($view->originNode['name']) ? (string) $view->originNode['name'] : null,
            ];
            foreach ($view->stops as $stop) {
                $parts[] = $stop->orderReference;
                $parts[] = $stop->customerName;
            }

            $haystack = mb_strtolower(implode(' ', array_filter($parts)));

            return $haystack !== '' && str_contains($haystack, $needle);
        }));
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
