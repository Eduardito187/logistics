<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use DateTimeImmutable;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Promise\Domain\Contract\CapacityCalendar;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Implementación del puerto de calendario de capacidad apoyada en el contexto de capacidad.
 *
 * Pregunta al QueryBus los cupos del día (modelo de lectura de capacity) y devuelve, para la zona pedida,
 * el cupo libre de cada franja en unidad de entregas (`deliveries`). A diferencia del AvailabilityProvider
 * —que solo lista franjas con cupo para cotizar una puntual—, aquí se conserva cuánto queda disponible,
 * que es lo que el cálculo de promesa necesita para ofrecer y priorizar fechas.
 *
 * El cruce entre contextos es por el bus y por primitivos (zoneId string, fechas): este adaptador no
 * importa infraestructura de capacity ni su repositorio, solo su mensaje de consulta público.
 */
final readonly class CapacityBackedCapacityCalendar implements CapacityCalendar
{
    public function __construct(private QueryBus $queryBus) {}

    public function availableSlots(string $zoneId, DateTimeImmutable $date): array
    {
        $day = $date->setTime(0, 0, 0);

        /** @var list<CapacityView> $buckets */
        $buckets = $this->queryBus->ask(new ListBucketsByDateQuery($day->format('Y-m-d')));

        $slots = [];
        foreach ($buckets as $bucket) {
            if ($bucket->zoneId !== $zoneId) {
                continue;
            }
            if ($bucket->unit !== CapacityUnit::Deliveries->value) {
                continue;
            }
            if ($bucket->available <= 0.0) {
                continue;
            }

            $slots[] = SlotCapacity::of($bucket->timeSlot, $bucket->available);
        }

        return $slots;
    }
}
