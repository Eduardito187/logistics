<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\CalculateDeliveryPromise;

use DateTimeImmutable;
use Logistics\Promise\Application\ReadModel\PromiseOptionView;
use Logistics\Promise\Domain\Contract\CapacityCalendar;
use Logistics\Promise\Domain\Contract\CorridorAvailabilityProvider;
use Logistics\Promise\Domain\Contract\CoverageProfileProvider;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Service\DeliveryPromiseCalculator;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Orquesta el cálculo de la promesa: reúne la cobertura (perfil de la zona), el corredor (red) y la
 * capacidad (cupo por franja) a través de los puertos del dominio, y deja que el servicio puro
 * DeliveryPromiseCalculator los combine en las opciones ofrecibles.
 *
 * Primero resuelve los días servibles (cobertura + corredor + tiempo base); recién de esos días consulta
 * la capacidad, para no pedirle a capacity fechas que de todas formas no se pueden prometer. No escribe
 * nada: es una consulta de solo lectura.
 *
 * @implements QueryHandler<CalculateDeliveryPromise, list<PromiseOptionView>>
 */
final readonly class CalculateDeliveryPromiseHandler implements QueryHandler
{
    public function __construct(
        private CoverageProfileProvider $coverageProfiles,
        private CorridorAvailabilityProvider $corridors,
        private CapacityCalendar $capacity,
        private DeliveryPromiseCalculator $calculator,
        private Clock $clock,
    ) {}

    /**
     * @return list<PromiseOptionView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof CalculateDeliveryPromise) {
            throw PromiseException::because('CalculateDeliveryPromiseHandler solo maneja CalculateDeliveryPromise');
        }

        $zoneId = trim($query->zoneId);
        if ($zoneId === '') {
            throw PromiseException::because('la zona no puede estar vacía');
        }

        // Sin cobertura (zona inexistente o inactiva) no hay nada que prometer.
        $profile = $this->coverageProfiles->profileFor($zoneId);
        if ($profile === null) {
            return [];
        }

        $now = $this->clock->now();
        $from = $this->parseFrom($query->fromDate, $now);
        $corridorServed = $this->corridors->servesZone($zoneId);

        $dates = $this->calculator->serviceableDates($profile, $corridorServed, $from, $now, $query->horizonDays);

        $views = [];
        foreach ($dates as $date) {
            $slots = $this->capacity->availableSlots($zoneId, $date);
            foreach ($this->calculator->optionsFor($profile, $date, $slots) as $option) {
                $views[] = PromiseOptionView::fromOption($option);
            }
        }

        return $views;
    }

    private function parseFrom(?string $fromDate, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($fromDate === null || $fromDate === '') {
            return $now;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
        if ($date === false) {
            throw PromiseException::because("fecha de inicio inválida (se esperaba Y-m-d): {$fromDate}");
        }

        return $date;
    }
}
