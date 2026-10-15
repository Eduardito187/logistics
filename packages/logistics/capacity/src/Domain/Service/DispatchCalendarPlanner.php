<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Service;

use DateInterval;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\PlannedBucket;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Proyecta las reglas de cupo sobre el horizonte y produce los cupos que deberían existir. Es un
 * servicio de dominio PHP puro: no toca persistencia ni framework; solo calcula.
 *
 * Recorre los días hacia adelante desde la fecha de inicio y toma solo los días de servicio (día de
 * la semana habilitado y no feriado) hasta completar el horizonte en días de servicio. Para cada día
 * de servicio, por cada ventana ofrecida ese día, por cada unidad con regla de cupo y por cada scope
 * configurado, emite un PlannedBucket con el total que marcan las reglas (afinamiento por franja
 * incluido). Determinista: el orden es fecha → franja → unidad → scope.
 */
final class DispatchCalendarPlanner
{
    /**
     * Días calendario máximos a inspeccionar para completar el horizonte, como salvaguarda (un
     * horizonte de N días de servicio nunca necesita más de N*7 + un mes de ventana por feriados).
     */
    private const int SCAN_SAFETY_DAYS = 31;

    /**
     * @param  int|null            $horizonOverride horizonte en días de servicio que reemplaza el de las reglas
     * @return list<PlannedBucket>
     */
    public function plan(CapacityRuleset $ruleset, SlotDate $from, ?int $horizonOverride = null): array
    {
        $horizon = $horizonOverride ?? $ruleset->horizonDays();
        if ($horizon < 1) {
            return [];
        }

        $scopes = $ruleset->scopes();
        $units = $ruleset->units();
        if ($scopes === [] || $units === []) {
            return [];
        }

        $plan = [];
        $serviceDaysSeen = 0;
        $cursor = $from->toDateTime();
        $maxScan = $horizon * 7 + self::SCAN_SAFETY_DAYS;

        for ($scanned = 0; $scanned < $maxScan && $serviceDaysSeen < $horizon; $scanned++) {
            $date = SlotDate::fromDateTime($cursor);
            $cursor = $cursor->add(new DateInterval('P1D'));

            if (!$ruleset->isServiceDay($date)) {
                continue;
            }
            $serviceDaysSeen++;

            foreach ($ruleset->windowsFor($date) as $window) {
                $this->planWindow($plan, $ruleset, $scopes, $units, $date, $window);
            }
        }

        return $plan;
    }

    /**
     * Agrega al plan los cupos de una fecha y ventana, por unidad (con total aplicable) y por scope.
     *
     * @param list<PlannedBucket> $plan
     * @param list<BucketScope>   $scopes
     * @param list<CapacityUnit>  $units
     */
    private function planWindow(
        array &$plan,
        CapacityRuleset $ruleset,
        array $scopes,
        array $units,
        SlotDate $date,
        TimeSlot $window,
    ): void {
        foreach ($units as $unit) {
            $total = $ruleset->totalFor($unit, $window);
            if ($total === null) {
                continue;
            }
            foreach ($scopes as $scope) {
                $plan[] = new PlannedBucket($scope, $date, $window, $unit, $total);
            }
        }
    }
}
