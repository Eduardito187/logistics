<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use Logistics\Planning\Domain\Contract\AutoPlanSettingsProvider;
use Logistics\Planning\Domain\Contract\PlanningOriginLocator;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Contract\ShipmentCoordinatesProvider;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Model\PlannedStop;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Throwable;

/**
 * Servicio de aplicación REUTILIZABLE que (re)calcula la geometría por calles de un plan a partir de su
 * estado actual: depósito (nodo CD de origen configurado) seguido de las paradas en orden de secuencia con
 * coordenada (las no geocodificadas se omiten). Sin depósito resoluble, la línea arranca en la primera
 * parada. Lo usan los comandos que arman o cambian paradas (publicar, reordenar, asignar, mover, proponer),
 * el backfill y la lectura de la ruta en Ejecución cuando el plan todavía no tiene geometría.
 *
 * Nunca rompe al llamador: si el proveedor o la lectura de coordenadas fallan, no adjunta nada (el
 * proveedor OSRM ya degrada a línea recta por su cuenta). Vive en Application: solo orquesta contratos.
 */
final readonly class PlanGeometryRefresher
{
    public function __construct(
        private PlanRepository $plans,
        private RouteGeometryProvider $geometryProvider,
        private ShipmentCoordinatesProvider $coordinates,
        private PlanningOriginLocator $originLocator,
        private AutoPlanSettingsProvider $settings,
    ) {}

    /**
     * Calcula y adjunta la geometría al plan dado (sin persistirlo). Con `$onlyIfMissing` no toca un plan
     * que ya la tenga. Un plan publicado solo se completa si no tiene geometría (su hoja está congelada).
     * Devuelve true si adjuntó una geometría.
     */
    public function refresh(DeliveryPlan $plan, bool $onlyIfMissing = false): bool
    {
        $missing = $plan->geometry() === null;
        if ($onlyIfMissing && !$missing) {
            return false;
        }
        if ($plan->status() === PlanStatus::Published && !$missing) {
            return false;
        }

        try {
            $points = $this->pointsFor($plan);
            if ($points === []) {
                return false;
            }

            $geometry = $this->geometryProvider->geometryFor($points);
        } catch (Throwable) {
            return false;
        }

        if ($geometry === null) {
            return false;
        }

        if ($plan->status() === PlanStatus::Published) {
            return $plan->fillMissingRouteGeometry($geometry);
        }

        $plan->attachRouteGeometry($geometry);

        return true;
    }

    /**
     * Asegura que el plan del camión y día dados tenga geometría persistida: si existe y le falta, la
     * calcula y guarda. Devuelve true si la guardó. No lanza (degrada a false).
     */
    public function ensureForVehicleAndDate(string $vehicleId, string $planDate): bool
    {
        try {
            foreach ($this->plans->findByDate(PlanDate::fromString($planDate)) as $plan) {
                if ($plan->vehicleId() !== $vehicleId) {
                    continue;
                }
                if ($this->refresh($plan, onlyIfMissing: true)) {
                    $this->plans->save($plan);

                    return true;
                }

                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Recorre los planes sin geometría (o todos con `$all`) y la calcula y persiste. Idempotente: un plan
     * que ya la tiene se saltea salvo `$all` (y aun así un plan publicado con geometría no se reescribe).
     *
     * @return array{scanned: int, updated: int, skipped: int}
     */
    public function backfill(?PlanDate $date, bool $all): array
    {
        $scanned = 0;
        $updated = 0;
        foreach ($this->plans->idsForGeometryBackfill($date, $all) as $planId) {
            $scanned++;
            $plan = $this->plans->findById($planId);
            if ($plan === null) {
                continue;
            }
            if ($this->refresh($plan, onlyIfMissing: !$all)) {
                $this->plans->save($plan);
                $updated++;
            }
        }

        return ['scanned' => $scanned, 'updated' => $updated, 'skipped' => $scanned - $updated];
    }

    /**
     * Depósito + paradas con coordenada en orden de secuencia. Con una sola parada y sin depósito no hay
     * línea posible (se devuelve vacío).
     *
     * @return list<GeoPoint>
     */
    private function pointsFor(DeliveryPlan $plan): array
    {
        $stops = $plan->stops();
        usort($stops, static fn (PlannedStop $a, PlannedStop $b): int => $a->sequence()->value <=> $b->sequence()->value);

        $shipmentIds = array_map(static fn (PlannedStop $stop): string => $stop->shipmentId(), $stops);
        $byShipment = $this->coordinates->coordinatesFor($shipmentIds);

        $points = [];
        $depot = $this->depotFor($plan);
        if ($depot !== null) {
            $points[] = $depot;
        }
        foreach ($shipmentIds as $shipmentId) {
            if (isset($byShipment[$shipmentId])) {
                $points[] = $byShipment[$shipmentId];
            }
        }

        return count($points) >= 2 ? $points : [];
    }

    private function depotFor(DeliveryPlan $plan): ?GeoPoint
    {
        try {
            $originNodeId = $this->settings->parametersFor($plan->date(), PlanStrategy::Optimization, [])->originNodeId;

            return $this->originLocator->locate($originNodeId);
        } catch (Throwable) {
            return null;
        }
    }
}
