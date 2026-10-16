<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\ReadModel;

/**
 * Proyección de lectura de un día del calendario de andén de recepción: por franja, cuántas citas hay.
 * Espejo del calendario de despacho (DispatchCalendar), para la operación de recepción.
 */
final readonly class ReceivingDockDayView
{
    /**
     * @param list<array{time_slot: string, appointments: int}> $slots
     */
    private function __construct(
        public string $date,
        public int $appointments,
        public array $slots,
    ) {}

    /**
     * @param list<array{time_slot: string, appointments: int}> $slots
     */
    public static function of(string $date, array $slots): self
    {
        $total = 0;
        foreach ($slots as $slot) {
            $total += $slot['appointments'];
        }

        return new self($date, $total, array_values($slots));
    }

    /**
     * @return array{date: string, appointments: int, slots: list<array{time_slot: string, appointments: int}>}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'appointments' => $this->appointments,
            'slots' => $this->slots,
        ];
    }
}
