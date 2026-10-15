<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\ReadModel;

use Logistics\Promise\Domain\ValueObject\PromiseOption;

/**
 * Modelo de lectura de una opción de promesa calculada: fecha, día de la semana, franja y cupo libre.
 * Forma plana en primitivos, lista para la API de canales. Si se cachea, se cachea este toArray()
 * (regla 7), nunca el objeto de dominio.
 */
final readonly class PromiseOptionView
{
    public function __construct(
        public string $slotDate,
        public int $weekday,
        public string $timeSlot,
        public float $available,
    ) {}

    public static function fromOption(PromiseOption $option): self
    {
        return new self(
            $option->dateString(),
            $option->weekday(),
            $option->timeSlot,
            $option->available,
        );
    }

    /**
     * @return array{slot_date: string, weekday: int, time_slot: string, available: float}
     */
    public function toArray(): array
    {
        return [
            'slot_date' => $this->slotDate,
            'weekday' => $this->weekday,
            'time_slot' => $this->timeSlot,
            'available' => $this->available,
        ];
    }
}
