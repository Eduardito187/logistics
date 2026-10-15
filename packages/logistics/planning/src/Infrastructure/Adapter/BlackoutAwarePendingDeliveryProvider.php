<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayQuery;
use Logistics\Capacity\Application\ReadModel\DispatchDayAvailabilityView;
use Logistics\Coverage\Domain\Contract\MunicipalityLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Decorador del puerto de pedidos pendientes que DESCARTA los candidatos cuyo municipio tiene la fecha
 * del plan BLOQUEADA (feriado u otro motivo, propio del municipio o global). El auto-planificador arma el
 * plan "para el día X": si ese día está bloqueado para el municipio de un pedido, ese pedido no se
 * planifica ese día (quedará pendiente para una corrida de otra fecha).
 *
 * Vive en Infrastructure porque resuelve el municipio del pedido por sus coordenadas con el localizador de
 * Cobertura (MunicipalityLocator) y consulta el bloqueo al contexto de Capacidad por el QueryBus, ambos en
 * primitivos de dominio: planificación no acopla su dominio a esos contextos. El día que capacity/coverage
 * se extraigan a microservicios, este decorador pasa a llamar sus APIs sin tocar el motor de planificación.
 *
 * A diferencia de la promesa —que además respeta el horizonte ACTIVO de cupos—, aquí solo se filtra por
 * día bloqueado: la planificación reparte envíos ya recibidos y no debe vaciarse solo porque el horizonte
 * comercial de cupos de un municipio haya terminado; sí debe evitar un feriado del municipio.
 *
 * Tolerante: si no se puede resolver el municipio de un pedido (coordenadas fuera de toda zona), se
 * consulta el alcance global, de modo que un bloqueo global igual lo frena y, si no hay bloqueo global, el
 * pedido se mantiene (comportamiento de siempre).
 */
final readonly class BlackoutAwarePendingDeliveryProvider implements PendingDeliveryProvider
{
    public function __construct(
        private PendingDeliveryProvider $inner,
        private MunicipalityLocator $municipalities,
        private QueryBus $queryBus,
    ) {}

    public function pendingFor(PlanDate $date): array
    {
        $candidates = $this->inner->pendingFor($date);
        $day = $date->toString();

        // Memo del veredicto de bloqueo por municipio para esta fecha: una sola consulta por municipio
        // presente entre los candidatos (muchos pedidos comparten municipio).
        /** @var array<string, bool> $blocked */
        $blocked = [];

        $kept = [];
        foreach ($candidates as $candidate) {
            $municipalityId = $this->municipalities
                ->locate(GeoPoint::of($candidate->latitude, $candidate->longitude))
                ?->toString();

            $municipalityKey = $municipalityId ?? '';
            if (!array_key_exists($municipalityKey, $blocked)) {
                $blocked[$municipalityKey] = $this->isBlocked($municipalityId, $day);
            }
            if ($blocked[$municipalityKey]) {
                // Día bloqueado para el municipio del pedido: no se planifica en esta fecha.
                continue;
            }

            $kept[] = $candidate;
        }

        return $kept;
    }

    /**
     * ¿La fecha está bloqueada para el municipio (o globalmente, cuando el municipio no se resolvió)?
     */
    private function isBlocked(?string $municipalityId, string $date): bool
    {
        /** @var DispatchDayAvailabilityView $view */
        $view = $this->queryBus->ask(new CheckDispatchDayQuery($date, $municipalityId));

        return $view->blocked;
    }
}
