<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Capacity\Infrastructure\Cache\CapacityGenerationKey;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/**
 * Cache (Valkey) de las franjas disponibles por zona y fecha (frente E — escala).
 *
 * Es el modelo de lectura más caliente del flujo de promesa: cada cotización de un canal pregunta por
 * las franjas con cupo antes de prometer, y ese cálculo barre los cupos del día en capacity. Este
 * decorador lo memoiza en Valkey.
 *
 * Respeta la regla #7: NO cachea objetos de dominio. Guarda la forma primitiva —una lista de pares
 * {slot_date, time_slot}— y reconstruye los DeliverySlot al leer. Así el valor en cache es un array
 * plano serializable, nunca un objeto de dominio.
 *
 * Invalidación por evento: la clave incluye la "generación" del día (CapacityGenerationKey), un contador
 * que capacity sube cada vez que cambia un cupo de esa fecha. Al cambiar la capacidad, la generación
 * sube y la entrada vieja deja de consultarse (expira sola por TTL). Es invalidación sin borrado: no hay
 * que saber qué zonas tocar, basta con que el número cambie. El TTL es la red de seguridad por si el
 * contador se pierde (cache volátil).
 */
final readonly class CachingAvailabilityProvider implements AvailabilityProvider
{
    /** Segundos de vida de la entrada; la disponibilidad cambia seguido, así que es corto. */
    private const int TTL_SECONDS = 60;

    private const string PREFIX = 'logistics:promise:slots:';

    public function __construct(
        private AvailabilityProvider $inner,
        private CacheRepository $cache,
    ) {}

    public function availableSlots(string $zoneId, DateTimeImmutable $date): array
    {
        $day = $date->format('Y-m-d');
        $generation = (int) $this->cache->get(CapacityGenerationKey::forDate($day), 0);
        $key = self::PREFIX.$zoneId.':'.$day.':g'.$generation;

        /** @var list<array{slot_date: string, time_slot: string}> $primitives */
        $primitives = $this->cache->remember(
            $key,
            self::TTL_SECONDS,
            fn (): array => array_map(
                static fn (DeliverySlot $slot): array => [
                    'slot_date' => $slot->dateString(),
                    'time_slot' => $slot->timeSlot,
                ],
                $this->inner->availableSlots($zoneId, $date),
            ),
        );

        return array_map(
            static fn (array $row): DeliverySlot => DeliverySlot::of(
                new DateTimeImmutable($row['slot_date']),
                $row['time_slot'],
            ),
            $primitives,
        );
    }
}
