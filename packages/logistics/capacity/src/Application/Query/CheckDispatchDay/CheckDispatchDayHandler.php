<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\CheckDispatchDay;

use Logistics\Capacity\Application\ReadModel\DispatchDayAvailabilityView;
use Logistics\Capacity\Domain\Contract\ActiveHorizonRepository;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve la disponibilidad de despacho de un (municipio, fecha): consulta el bloqueo (propio o global)
 * y el horizonte activo del alcance. Es la consulta que usan la UI del calendario, la oferta de slots y
 * la planificación para saber de antemano si un día es despachable.
 *
 * @implements QueryHandler<CheckDispatchDayQuery, DispatchDayAvailabilityView>
 */
final readonly class CheckDispatchDayHandler implements QueryHandler
{
    public function __construct(
        private DispatchBlackoutRepository $blackouts,
        private ActiveHorizonRepository $horizons,
    ) {}

    public function handle(Query $query): DispatchDayAvailabilityView
    {
        assert($query instanceof CheckDispatchDayQuery);

        $date = SlotDate::fromString($query->date);
        $municipalityId = $query->municipalityId;

        // El motivo visible, si hay un bloqueo concreto: prioriza el del municipio y cae al global.
        $blackout = $this->blackouts->findByDate($date, $municipalityId);
        if ($blackout === null && $municipalityId !== null) {
            $blackout = $this->blackouts->findByDate($date, null);
        }

        $horizon = $this->horizons->findFor($municipalityId);
        // Sin horizonte registrado, no se restringe por horizonte (compatibilidad con cupos a mano).
        $withinHorizon = $horizon === null || $horizon->covers($date);

        return new DispatchDayAvailabilityView(
            $date->toString(),
            $municipalityId,
            $blackout !== null,
            $blackout?->reason(),
            $withinHorizon,
            $horizon?->activeUntil()->toString(),
        );
    }
}
