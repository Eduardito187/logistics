<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;

/**
 * Implementación Eloquent del repositorio de planes.
 *
 * save() es un upsert transaccional: reescribe la fila del plan y regenera sus paradas (borrar e
 * insertar) para que la persistencia refleje exactamente el estado del agregado, que es la fuente de
 * verdad de la secuencia, la ETA y el carril de cada parada.
 */
final class EloquentPlanRepository implements PlanRepository
{
    public function __construct(
        private readonly PlanMapper $mapper,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function nextIdentity(): PlanId
    {
        return PlanId::generate();
    }

    public function save(DeliveryPlan $plan): void
    {
        $this->connections->connection()->transaction(function () use ($plan): void {
            PlanRecord::query()->updateOrCreate(
                ['id' => $plan->id()->toString()],
                $this->mapper->toPlanRow($plan),
            );

            $planId = $plan->id()->toString();

            // Regenera las paradas: el agregado es la fuente de verdad de orden, ETA y carril.
            PlannedStopRecord::query()->where('plan_id', $planId)->delete();

            foreach ($plan->stops() as $stop) {
                PlannedStopRecord::query()->create($this->mapper->toStopRow($plan, $stop));
            }
        });
    }

    public function findById(PlanId $id): ?DeliveryPlan
    {
        $record = PlanRecord::query()
            ->with('stops')
            ->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByDate(PlanDate $date): array
    {
        return PlanRecord::query()
            ->with('stops')
            ->where('plan_date', $date->toString())
            ->orderBy('vehicle_id')
            ->get()
            ->map(fn (PlanRecord $record): DeliveryPlan => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }

    public function idsForGeometryBackfill(?PlanDate $date, bool $all): array
    {
        $query = PlanRecord::query()->orderBy('plan_date')->orderBy('vehicle_id');
        if ($date !== null) {
            $query->where('plan_date', $date->toString());
        }
        if (!$all) {
            $query->whereNull('route_geometry');
        }

        /** @var list<string> $ids */
        $ids = $query->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();

        return array_map(static fn (string $id): PlanId => PlanId::fromString($id), $ids);
    }
}
