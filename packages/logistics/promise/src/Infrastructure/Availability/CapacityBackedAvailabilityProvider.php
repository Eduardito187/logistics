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
 *
 * Además del cupo libre, cada franja debe caer en un día DESPACHABLE para el municipio de su cupo: ni
 * bloqueado (feriado u otro motivo) ni fuera del horizonte activo de cupos de ese municipio. El municipio
 * lo trae el propio cupo (CapacityView::municipalityId, el eje por el que se activan los cupos); cuando el
 * cupo no tiene municipio (cupos a mano, legado por zona/nodo) se consulta el alcance global. Así el canal
 * nunca ve una franja de un día que se feriado o que quedó fuera del horizonte vigente.
 */
final readonly class CapacityBackedAvailabilityProvider implements AvailabilityProvider
{
    public function __construct(
        private QueryBus $queryBus,
        private DispatchDayGate $dispatchDay,
    ) {}

    public function availableSlots(string $zoneId, DateTimeImmutable $date): array
    {
        $day = $date->setTime(0, 0, 0);

        /** @var list<CapacityView> $buckets */
        $buckets = $this->queryBus->ask(new ListBucketsByDateQuery($day->format('Y-m-d')));

        // Memo del veredicto de despacho por municipio dentro de este día: todos los cupos del día
        // comparten fecha, así se consulta a capacity una sola vez por municipio presente.
        /** @var array<string, bool> $dispatchable */
        $dispatchable = [];

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

            $municipalityKey = $bucket->municipalityId ?? '';
            if (!array_key_exists($municipalityKey, $dispatchable)) {
                $dispatchable[$municipalityKey] = $this->dispatchDay->isDispatchable($bucket->municipalityId, $day);
            }
            if (!$dispatchable[$municipalityKey]) {
                // Día bloqueado o fuera del horizonte activo del municipio: no se ofrece esta franja.
                continue;
            }

            // La franja de capacity ('HH:MM-HH:MM') es una etiqueta válida de DeliverySlot, se usa tal cual.
            $slots[] = DeliverySlot::of($day, $bucket->timeSlot);
        }

        return $slots;
    }
}
