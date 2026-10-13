<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use DateTimeImmutable;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/**
 * Stub determinista del puerto de disponibilidad. Ofrece dos franjas fijas (AM y PM) para cualquier
 * fecha que no sea pasada; para fechas pasadas no ofrece ninguna.
 *
 * Es un reemplazo temporal: la implementación real consultará `logistics/capacity` (cupos) y
 * `logistics/coverage` (si la zona cubre la fecha) detrás de este mismo contrato, sin tocar el dominio.
 */
final class StubAvailabilityProvider implements AvailabilityProvider
{
    private const array TIME_SLOTS = ['AM', 'PM'];

    public function availableSlots(string $zoneId, DateTimeImmutable $date): array
    {
        $day = $date->setTime(0, 0, 0);
        $today = new DateTimeImmutable('today');
        if ($day < $today) {
            return [];
        }

        $slots = [];
        foreach (self::TIME_SLOTS as $timeSlot) {
            $slots[] = DeliverySlot::of($day, $timeSlot);
        }

        return $slots;
    }
}
