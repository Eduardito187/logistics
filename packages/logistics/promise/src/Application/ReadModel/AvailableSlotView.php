<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\ReadModel;

use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/**
 * Modelo de lectura de una franja disponible: lo que se le ofrece al canal para prometer. Forma plana
 * en primitivos.
 */
final readonly class AvailableSlotView
{
    public function __construct(
        public string $slotDate,
        public string $timeSlot,
    ) {}

    public static function fromSlot(DeliverySlot $slot): self
    {
        return new self($slot->dateString(), $slot->timeSlot);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slot_date' => $this->slotDate,
            'time_slot' => $this->timeSlot,
        ];
    }
}
