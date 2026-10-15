<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use DateTimeImmutable;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchCalendar;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Implementación del calendario de despacho por MUNICIPIO apoyada en el contexto de capacidad.
 *
 * Pregunta al QueryBus los cupos del día del municipio (ListBucketsByDate ya filtra por municipio) y
 * devuelve, en unidad de entregas (`deliveries`) y con cupo todavía disponible, el cupo libre de cada
 * franja. Además, solo ofrece franjas de un día DESPACHABLE para el municipio: ni bloqueado (feriado u
 * otro motivo, propio o global) ni fuera del horizonte activo de cupos (lo decide el DispatchDayGate,
 * también contra capacity). Así el canal nunca ve una franja de un día que dejó de ser válido aunque
 * todavía tenga cupos cargados.
 *
 * El cruce entre contextos es por el bus y por primitivos (municipio string, fechas): este adaptador no
 * importa infraestructura de capacity ni su repositorio, solo sus mensajes de consulta públicos.
 */
final readonly class CapacityBackedMunicipalityDispatchCalendar implements MunicipalityDispatchCalendar
{
    public function __construct(
        private QueryBus $queryBus,
        private DispatchDayGate $dispatchDay,
    ) {}

    public function availableSlots(string $municipalityId, DateTimeImmutable $date): array
    {
        $day = $date->setTime(0, 0, 0);

        // El día bloqueado o fuera del horizonte activo del municipio no ofrece nada: un solo chequeo.
        if (!$this->dispatchDay->isDispatchable($municipalityId, $day)) {
            return [];
        }

        /** @var list<CapacityView> $buckets */
        $buckets = $this->queryBus->ask(new ListBucketsByDateQuery($day->format('Y-m-d'), $municipalityId));

        $slots = [];
        foreach ($buckets as $bucket) {
            if ($bucket->municipalityId !== $municipalityId) {
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
