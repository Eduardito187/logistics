<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetDeliveryCalendarDay;

use Logistics\Capacity\Application\ReadModel\DeliveryCalendarDayView;
use Logistics\Capacity\Application\ReadModel\DeliveryCalendarSlotView;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutSlotRepository;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Arma el calendario de entregas de un día: el bloqueo de día entero (feriado u otro motivo) y sus franjas
 * horarias, cada una con su cupo definido (null si no hay bucket), su consumo, su unidad y si está
 * bloqueada —por la franja o por el día— y con qué motivo.
 *
 * Con municipio, las franjas son las de los cupos de ese municipio; sin municipio, el consolidado GLOBAL:
 * suma de `capacity`/`used` por (franja, unidad) de todos los cupos de despacho por municipio. Las franjas
 * bloqueadas sin cupo definido aparecen igual, con `capacity` null en unidad de entregas. El resultado va
 * ordenado por hora de inicio.
 *
 * @implements QueryHandler<GetDeliveryCalendarDayQuery, DeliveryCalendarDayView>
 */
final readonly class GetDeliveryCalendarDayHandler implements QueryHandler
{
    public function __construct(
        private CapacityRepository $buckets,
        private DispatchBlackoutRepository $days,
        private DispatchBlackoutSlotRepository $slots,
    ) {}

    public function handle(Query $query): DeliveryCalendarDayView
    {
        assert($query instanceof GetDeliveryCalendarDayQuery);

        $date = SlotDate::fromString($query->date);
        $municipalityId = $query->municipalityId;

        [$dayBlocked, $dayReason] = $this->dayBlock($date, $municipalityId);
        $slotReasons = $this->slotReasons($date, $municipalityId);
        $slots = $this->buildSlots($date, $municipalityId, $dayBlocked, $dayReason, $slotReasons);

        return new DeliveryCalendarDayView($date->toString(), $dayBlocked, $dayReason, $slots);
    }

    /**
     * Bloqueo del DÍA entero, priorizando el del municipio y cayendo al global.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function dayBlock(SlotDate $date, ?string $municipalityId): array
    {
        $block = $this->days->findByDate($date, $municipalityId);
        if ($block === null && $municipalityId !== null) {
            $block = $this->days->findByDate($date, null);
        }

        return [$block !== null, $block?->reason()];
    }

    /**
     * Motivo de cada FRANJA bloqueada del día, por franja. Prioriza el bloqueo del municipio sobre el
     * global.
     *
     * @return array<string, string>
     */
    private function slotReasons(SlotDate $date, ?string $municipalityId): array
    {
        $reasons = [];
        $fromMunicipality = [];
        foreach ($this->slots->listForDate($date, $municipalityId) as $block) {
            $timeSlot = $block->slot()->toString();
            $isMunicipality = $block->municipalityId() !== null;
            $overrides = $isMunicipality && !($fromMunicipality[$timeSlot] ?? false);
            if (!isset($reasons[$timeSlot]) || $overrides) {
                $reasons[$timeSlot] = $block->reason();
                $fromMunicipality[$timeSlot] = $isMunicipality;
            }
        }

        return $reasons;
    }

    /**
     * Franjas del día: las derivadas de los cupos (agregadas) más las bloqueadas sin cupo. Ordenadas por
     * hora de inicio (y por unidad para estabilidad).
     *
     * @param  array<string, string>          $slotReasons
     * @return list<DeliveryCalendarSlotView>
     */
    private function buildSlots(
        SlotDate $date,
        ?string $municipalityId,
        bool $dayBlocked,
        ?string $dayReason,
        array $slotReasons,
    ): array {
        $views = [];
        $covered = [];
        foreach ($this->aggregateBuckets($date, $municipalityId) as $entry) {
            $timeSlot = $entry['time_slot'];
            $covered[$timeSlot] = true;
            $slotReason = $slotReasons[$timeSlot] ?? null;
            $blocked = $dayBlocked || $slotReason !== null;
            $views[] = new DeliveryCalendarSlotView(
                $timeSlot,
                $entry['capacity'],
                $entry['used'],
                $entry['unit'],
                $blocked,
                $slotReason ?? ($dayBlocked ? $dayReason : null),
            );
        }

        // Franja bloqueada que no tiene ningún cupo definido: se muestra igual, con cupo null.
        foreach ($slotReasons as $timeSlot => $reason) {
            if (!isset($covered[$timeSlot])) {
                $views[] = new DeliveryCalendarSlotView($timeSlot, null, 0.0, CapacityUnit::Deliveries->value, true, $reason);
            }
        }

        usort($views, static fn (DeliveryCalendarSlotView $a, DeliveryCalendarSlotView $b): int => [$a->timeSlot, $a->unit] <=> [$b->timeSlot, $b->unit]);

        return $views;
    }

    /**
     * Agrega los cupos de DESPACHO por municipio del día en entradas por (franja, unidad), sumando cupo y
     * consumo. Con municipio, solo los de ese municipio; sin municipio, todos (consolidado global). Los
     * cupos de nodo/zona y los de recepción (inbound) quedan fuera: el calendario de entregas es por
     * municipio.
     *
     * @return array<string, array{time_slot: string, unit: string, capacity: float, used: float}>
     */
    private function aggregateBuckets(SlotDate $date, ?string $municipalityId): array
    {
        $entries = [];
        foreach ($this->buckets->byDate($date) as $bucket) {
            $scope = $bucket->scope();
            if (!$scope->isDispatch() || $scope->municipalityId === null) {
                continue;
            }
            if ($municipalityId !== null && $scope->municipalityId !== $municipalityId) {
                continue;
            }

            $timeSlot = $bucket->slot()->toString();
            $unit = $bucket->unit()->value;
            $key = $timeSlot.'|'.$unit;
            $entries[$key] ??= ['time_slot' => $timeSlot, 'unit' => $unit, 'capacity' => 0.0, 'used' => 0.0];
            $entries[$key]['capacity'] += $bucket->total()->value();
            $entries[$key]['used'] += $bucket->used()->value();
        }

        return $entries;
    }
}
