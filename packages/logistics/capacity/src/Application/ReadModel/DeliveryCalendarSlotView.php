<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

/**
 * Modelo de lectura de UNA franja horaria del calendario de entregas de un día: su cupo definido
 * (`capacity`, null si no hay bucket para esa franja), lo consumido (`used`), la unidad, y si está
 * bloqueada (por la franja o por el día entero) y su motivo. Forma plana y primitiva.
 */
final readonly class DeliveryCalendarSlotView
{
    public function __construct(
        public string $timeSlot,
        public ?float $capacity,
        public float $used,
        public string $unit,
        public bool $blocked,
        public ?string $reason,
    ) {}

    /**
     * @return array{time_slot: string, capacity: float|null, used: float, unit: string, blocked: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'time_slot' => $this->timeSlot,
            'capacity' => $this->capacity,
            'used' => $this->used,
            'unit' => $this->unit,
            'blocked' => $this->blocked,
            'reason' => $this->reason,
        ];
    }
}
