<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\ReadModel;

use Logistics\Promise\Domain\ValueObject\SlotCapacity;

/**
 * Modelo de lectura de una franja prometible a un cliente para su municipio: municipio resuelto, día,
 * etiqueta de franja y cuántas entregas quedan disponibles. Forma plana en primitivos, SIN PII (ni el
 * envío, ni el cliente, ni la dirección): es lo que el canal muestra como opciones de entrega.
 */
final readonly class MunicipalitySlotView
{
    public function __construct(
        public string $municipalityId,
        public string $slotDate,
        public string $timeSlot,
        public float $available,
    ) {}

    public static function fromSlotCapacity(string $municipalityId, string $slotDate, SlotCapacity $capacity): self
    {
        return new self($municipalityId, $slotDate, $capacity->timeSlot, $capacity->available);
    }

    /**
     * @return array{municipality_id: string, slot_date: string, time_slot: string, available: float}
     */
    public function toArray(): array
    {
        return [
            'municipality_id' => $this->municipalityId,
            'slot_date' => $this->slotDate,
            'time_slot' => $this->timeSlot,
            'available' => $this->available,
        ];
    }
}
