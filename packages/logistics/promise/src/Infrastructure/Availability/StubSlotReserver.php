<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Availability;

use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/**
 * Stub determinista del puerto de reserva de cupo. Lleva en memoria qué franjas tomó y concede cupo
 * ilimitado: reservar siempre devuelve true y liberar nunca falla.
 *
 * Es un reemplazo temporal: la implementación real descontará cupo en `logistics/capacity` (con locks
 * y transacciones) detrás de este mismo contrato, sin tocar el dominio.
 */
final class StubSlotReserver implements SlotReserver
{
    /** @var array<string, int> Veces que cada clave (zona|franja) está reservada. */
    private array $reserved = [];

    public function reserve(string $zoneId, DeliverySlot $slot): bool
    {
        $key = $this->keyFor($zoneId, $slot);
        $this->reserved[$key] = ($this->reserved[$key] ?? 0) + 1;

        return true;
    }

    public function release(string $zoneId, DeliverySlot $slot): void
    {
        $key = $this->keyFor($zoneId, $slot);
        if (($this->reserved[$key] ?? 0) > 0) {
            $this->reserved[$key]--;
        }
    }

    private function keyFor(string $zoneId, DeliverySlot $slot): string
    {
        return $zoneId.'::'.$slot->toCanonical();
    }
}
