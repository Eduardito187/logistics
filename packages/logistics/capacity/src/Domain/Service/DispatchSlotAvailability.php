<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Service;

use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutSlotRepository;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Responde si una franja horaria concreta de un (municipio, fecha) está bloqueada para despacho. Una
 * franja queda bloqueada si el DÍA entero lo está (feriado u otro motivo, propio o global) o si ESA franja
 * puntual lo está (propia o global). Centraliza esa regla para quienes ofrecen cupo (lecturas de
 * disponibilidad) y para el enforcement del consumo.
 *
 * Es PHP puro salvo por depender de los puertos de repositorio (interfaces del dominio): no toca framework
 * ni persistencia concreta.
 */
final readonly class DispatchSlotAvailability
{
    public function __construct(
        private DispatchBlackoutRepository $days,
        private DispatchBlackoutSlotRepository $slots,
    ) {}

    /**
     * ¿Está bloqueada SOLO la franja (no el día)? Un bloqueo específico de la franja para el municipio O un
     * bloqueo global de la franja la marcan como bloqueada. No mira el bloqueo de día entero.
     */
    public function isSlotBlocked(SlotDate $date, TimeSlot $slot, ?string $municipalityId): bool
    {
        return $this->slots->isBlocked($date, $slot, $municipalityId);
    }

    /**
     * ¿Está bloqueada la franja para ofrecer/agendar? Verdadero si el día entero está bloqueado o si la
     * franja puntual lo está (en ambos casos, propio del municipio o global). Es la regla que aplican las
     * lecturas de disponibilidad para EXCLUIR la franja.
     */
    public function isBlocked(SlotDate $date, TimeSlot $slot, ?string $municipalityId): bool
    {
        return $this->days->isBlocked($date, $municipalityId)
            || $this->slots->isBlocked($date, $slot, $municipalityId);
    }
}
