<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use DateTimeImmutable;
use Logistics\Planning\Domain\ValueObject\CrewShiftWindow;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;

/**
 * Política pura que ajusta una ruta resuelta a la ventana de turno de la tripulación: las paradas cuya
 * ETA (minutos desde la salida) supera el límite permitido —el menor entre lo que queda de ventana y la
 * jornada máxima— se QUITAN de la ruta, junto con todas las que vienen después en el orden de reparto
 * (la ruta es secuencial: si una parada ya excede el turno, las siguientes también). Las quitadas vuelven
 * como no asignadas para que queden pendientes (otro camión, otra corrida u operación manual).
 *
 * Limitaciones (documentadas): la duración se estima con la ETA de LLEGADA a cada parada, sin el tiempo
 * de servicio en la última ni el regreso al depósito, ni la preparación previa a la salida; por eso es una
 * cota conservadora (solo recorta lo que claramente excede). Una parada sin ETA no se puede estimar y se
 * conserva. Si se recorta, la geometría por calles queda desactualizada y se descarta (el comando de
 * backfill de geometría la recalcula).
 */
final readonly class ShiftWindowRouteGuard
{
    /**
     * Ajusta la ruta al turno: devuelve la ruta recortada (null si no quedó ninguna parada) y las paradas
     * quitadas, en su orden de reparto.
     *
     * @return array{route: ?PlannedRoute, removed: list<DeliveryCandidate>}
     */
    public function fit(PlannedRoute $route, DateTimeImmutable $departAt, CrewShiftWindow $window): array
    {
        $limit = $window->routeLimitMinutes($departAt);

        /** @var list<DeliveryCandidate> $kept */
        $kept = [];
        /** @var array<string, Eta> $keptEtas */
        $keptEtas = [];
        /** @var list<DeliveryCandidate> $removed */
        $removed = [];

        $exceeded = false;
        foreach ($route->candidates as $candidate) {
            $eta = $route->etas[$candidate->shipmentId] ?? null;
            if (!$exceeded && $eta !== null && $eta->minutesFromDeparture > $limit) {
                $exceeded = true;
            }

            if ($exceeded) {
                $removed[] = $candidate;

                continue;
            }

            $kept[] = $candidate;
            if ($eta !== null) {
                $keptEtas[$candidate->shipmentId] = $eta;
            }
        }

        if ($removed === []) {
            return ['route' => $route, 'removed' => []];
        }

        if ($kept === []) {
            return ['route' => null, 'removed' => $removed];
        }

        return [
            'route' => new PlannedRoute($route->vehicleId, $route->departmentId, $kept, $keptEtas, null),
            'removed' => $removed,
        ];
    }
}
