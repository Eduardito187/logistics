<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Model\PlannedStop;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\LoadLane;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Planning\Domain\ValueObject\StopSequence;

/**
 * Traduce entre el agregado DeliveryPlan y sus filas Eloquent. Toda la conversión a/desde primitivos
 * vive acá, para que ni el dominio ni el repositorio conozcan la forma de las tablas.
 */
final class PlanMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toPlanRow(DeliveryPlan $plan): array
    {
        return [
            'id' => $plan->id()->toString(),
            'plan_date' => $plan->date()->toString(),
            'vehicle_id' => $plan->vehicleId(),
            'status' => $plan->status()->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStopRow(DeliveryPlan $plan, PlannedStop $stop): array
    {
        $eta = $stop->eta();
        $lane = $stop->lane();

        return [
            'id' => $stop->id()->toString(),
            'plan_id' => $plan->id()->toString(),
            'shipment_id' => $stop->shipmentId(),
            'sequence' => $stop->sequence()->value,
            'eta' => $eta?->arrivalAt->format(DateTimeInterface::ATOM),
            'eta_minutes' => $eta?->minutesFromDeparture,
            'lane' => $lane?->label,
        ];
    }

    /**
     * Reconstruye el agregado completo (con sus paradas) desde sus filas.
     */
    public function toDomain(PlanRecord $record): DeliveryPlan
    {
        $stops = [];
        foreach ($record->stops as $stopRecord) {
            $stops[] = $this->toStopDomain($stopRecord);
        }

        return DeliveryPlan::reconstitute(
            PlanId::fromString($record->id),
            PlanDate::fromString($record->plan_date),
            $record->vehicle_id,
            PlanStatus::from($record->status),
            $stops,
        );
    }

    private function toStopDomain(PlannedStopRecord $record): PlannedStop
    {
        $eta = null;
        if ($record->eta !== null && $record->eta_minutes !== null) {
            $eta = Eta::at(new DateTimeImmutable($record->eta), $record->eta_minutes);
        }

        $lane = $record->lane !== null ? LoadLane::of($record->lane) : null;

        return PlannedStop::reconstitute(
            PlannedStopId::fromString($record->id),
            $record->shipment_id,
            StopSequence::of($record->sequence),
            $eta,
            $lane,
        );
    }
}
