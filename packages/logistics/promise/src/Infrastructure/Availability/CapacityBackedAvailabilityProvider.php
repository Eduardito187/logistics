<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use DateTimeImmutable;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Implementación real del puerto de disponibilidad apoyada en el contexto de capacidad.
 *
 * Pregunta al QueryBus los cupos del día (modelo de lectura de capacity) y ofrece como franjas de
 * entrega solo las de la ZONA pedida, en unidad de entregas (`deliveries`) y con cupo todavía
 * disponible (total − usado > 0). Así el canal nunca ve una franja que ya se agotó.
 *
 * El cruce entre contextos es por el bus y por primitivos (zoneId string, fechas): este adaptador no
 * importa infraestructura de capacity ni su repositorio, solo su mensaje de consulta público. El día
 * que capacity se extraiga a un microservicio, este adaptador pasa a llamar su API sin tocar el dominio
 * de promise. La cobertura de la zona para la fecha la garantiza que el cupo exista para ese scope.
 */
final readonly class CapacityBackedAvailabilityProvider implements AvailabilityProvider
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

            // La franja de capacity ('HH:MM-HH:MM') es una etiqueta válida de DeliverySlot, se usa tal cual.
            $slots[] = DeliverySlot::of($day, $bucket->timeSlot);
        }

        return $slots;
    }
}
