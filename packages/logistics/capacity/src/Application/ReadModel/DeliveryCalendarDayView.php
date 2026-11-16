<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

/**
 * Modelo de lectura del calendario de entregas de UN día: el bloqueo de día entero (feriado u otro motivo)
 * y sus franjas horarias con cupo, consumo y bloqueo por franja. Sin municipio, las franjas son el
 * consolidado GLOBAL (suma por franja y unidad de todos los municipios). Forma plana y primitiva.
 */
final readonly class DeliveryCalendarDayView
{
    /**
     * @param list<DeliveryCalendarSlotView> $slots
     */
    public function __construct(
        public string $date,
        public bool $blocked,
        public ?string $reason,
        public array $slots,
    ) {}

    /**
     * @return array{date: string, blocked: bool, reason: string|null, slots: list<array{time_slot: string, capacity: float|null, used: float, unit: string, blocked: bool, reason: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'blocked' => $this->blocked,
            'reason' => $this->reason,
            'slots' => array_map(static fn (DeliveryCalendarSlotView $slot): array => $slot->toArray(), $this->slots),
        ];
    }
}
