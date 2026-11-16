<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\CheckDispatchSlot;

use Logistics\Capacity\Application\ReadModel\DispatchSlotAvailabilityView;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutSlotRepository;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve si una franja horaria de un (municipio, fecha) está bloqueada para despacho. Prioriza el motivo
 * de la franja (propio del municipio, luego global) y cae al motivo del día entero (propio, luego global).
 * Es la consulta que la oferta de slots, la planificación y la reprogramación usan para rechazar una
 * franja bloqueada.
 *
 * @implements QueryHandler<CheckDispatchSlotQuery, DispatchSlotAvailabilityView>
 */
final readonly class CheckDispatchSlotHandler implements QueryHandler
{
    public function __construct(
        private DispatchBlackoutRepository $days,
        private DispatchBlackoutSlotRepository $slots,
    ) {}

    public function handle(Query $query): DispatchSlotAvailabilityView
    {
        assert($query instanceof CheckDispatchSlotQuery);

        $date = SlotDate::fromString($query->date);
        $slot = TimeSlot::fromString($query->timeSlot);
        $municipalityId = $query->municipalityId;

        $reason = $this->slotReason($date, $slot, $municipalityId) ?? $this->dayReason($date, $municipalityId);

        return new DispatchSlotAvailabilityView(
            $date->toString(),
            $slot->toString(),
            $municipalityId,
            $reason !== null,
            $reason,
        );
    }

    /**
     * Motivo del bloqueo de la FRANJA, priorizando el del municipio y cayendo al global. Null si no hay.
     */
    private function slotReason(SlotDate $date, TimeSlot $slot, ?string $municipalityId): ?string
    {
        $block = $this->slots->findByKey($date, $slot, $municipalityId);
        if ($block === null && $municipalityId !== null) {
            $block = $this->slots->findByKey($date, $slot, null);
        }

        return $block?->reason();
    }

    /**
     * Motivo del bloqueo del DÍA entero, priorizando el del municipio y cayendo al global. Null si no hay.
     */
    private function dayReason(SlotDate $date, ?string $municipalityId): ?string
    {
        $block = $this->days->findByDate($date, $municipalityId);
        if ($block === null && $municipalityId !== null) {
            $block = $this->days->findByDate($date, null);
        }

        return $block?->reason();
    }
}
