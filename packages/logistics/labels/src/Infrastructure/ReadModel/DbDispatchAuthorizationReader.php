<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\ReadModel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Logistics\Labels\Application\Contract\DispatchAuthorizationReader;
use Logistics\Labels\Application\ReadModel\DispatchAuthorization;
use Throwable;

/**
 * Autorización de despacho de un envío, leída en SOLO lectura de planificación, ejecución y flota (por el
 * query builder, sin acoplar sus dominios).
 *
 * POR QUÉ no alcanza con el plan publicado: antes la única vía era `planning_plan_stops` de un plan
 * PUBLICADO. Pero el flujo vigente del dueño asigna el camión desde el andén
 * (`POST /app/orders/{orden}/vehicle`), que abre la entrega (`execution_deliveries`) y suma la parada a la
 * ruta del camión (`execution_driver_route_stops`) SIN crear ni publicar un plan. Esos pedidos tenían camión
 * y aun así salían "sin plan publicado": el preparador quedaba bloqueado hasta que alguien publicara un plan
 * en el admin. El resto de la plataforma ya trata la parada en ruta igual que el plan publicado (el envío
 * pasa a PLANNED por cualquiera de las dos); acá se alinea la autorización con esa misma regla.
 *
 * Orden de evaluación:
 *  1. Parada en un plan PUBLICADO → autorizado (regla histórica, sin cambios).
 *  2. Entrega abierta, o parada vigente en la ruta de un camión → autorizado, SALVO que ese camión esté NO
 *     APTO (`fleet_vehicles.unfit_since` vigente): la asignación ya rechaza un camión no apto, pero puede
 *     quedar no apto DESPUÉS de asignado, y no se debe preparar carga para un camión que no puede salir.
 *  3. Nada de lo anterior → no autorizado ("sin plan publicado").
 *
 * Una parada RESCHEDULED no cuenta: el envío salió de esa ruta por un incidente mayor y espera replanificación.
 *
 * Cada fuente va en su try/catch: si una tabla de otro contexto no está disponible, esa vía no autoriza y
 * las demás siguen funcionando.
 */
final class DbDispatchAuthorizationReader implements DispatchAuthorizationReader
{
    /** Zona horaria operativa de la aptitud (la misma que usa ejecución al rechazar un camión no apto). */
    private const string TIMEZONE = 'America/La_Paz';

    /** Estado de la parada que la saca de la ruta sin haberse atendido (espejo de RouteStopStatus). */
    private const string STOP_RESCHEDULED = 'RESCHEDULED';

    /** Memo de la existencia de la columna de aptitud (una consulta al catálogo por instancia). */
    private ?bool $hasFitnessColumn = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function forShipment(string $shipmentId): DispatchAuthorization
    {
        if (trim($shipmentId) === '') {
            return DispatchAuthorization::notAssigned();
        }

        if ($this->inPublishedPlan($shipmentId)) {
            return DispatchAuthorization::granted();
        }

        $vehicleId = $this->assignedVehicle($shipmentId);
        if ($vehicleId === null) {
            return DispatchAuthorization::notAssigned();
        }

        return $this->isUnfit($vehicleId)
            ? DispatchAuthorization::vehicleUnfit()
            : DispatchAuthorization::granted();
    }

    /** ¿El envío tiene parada en un plan PUBLICADO? (vía de planificación). */
    private function inPublishedPlan(string $shipmentId): bool
    {
        try {
            return $this->connection->table('planning_plan_stops')
                ->join('planning_plans', 'planning_plans.id', '=', 'planning_plan_stops.plan_id')
                ->where('planning_plan_stops.shipment_id', $shipmentId)
                ->where('planning_plans.status', 'PUBLISHED')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Camión que la operación le asignó al envío fuera de planificación, o null si no tiene. Manda el de la
     * entrega (es donde está el envío en ejecución; al reasignar, la entrega se mueve al camión nuevo); si
     * todavía no hay entrega, el de su parada más reciente en una ruta.
     */
    private function assignedVehicle(string $shipmentId): ?string
    {
        return $this->deliveryVehicle($shipmentId) ?? $this->routeVehicle($shipmentId);
    }

    private function deliveryVehicle(string $shipmentId): ?string
    {
        try {
            $vehicleId = $this->connection->table('execution_deliveries')
                ->where('shipment_id', $shipmentId)
                ->value('vehicle_id');
        } catch (Throwable) {
            return null;
        }

        return is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null;
    }

    private function routeVehicle(string $shipmentId): ?string
    {
        try {
            $vehicleId = $this->connection->table('execution_driver_route_stops as s')
                ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
                ->where('s.shipment_id', $shipmentId)
                ->where('s.status', '!=', self::STOP_RESCHEDULED)
                ->orderByDesc('s.created_at')
                ->value('r.vehicle_id');
        } catch (Throwable) {
            return null;
        }

        return is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null;
    }

    /**
     * ¿El camión está NO APTO hoy? Misma regla que ejecución (`unfit_since` no nulo y ya vigente). Si la
     * columna no existe (flota sin la migración del checklist) o la lectura falla, no hay chequeo: la
     * aptitud es una guarda adicional y su ausencia no debe bloquear el despacho.
     */
    private function isUnfit(string $vehicleId): bool
    {
        try {
            $this->hasFitnessColumn ??= $this->connection->getSchemaBuilder()->hasColumn('fleet_vehicles', 'unfit_since');
            if (!$this->hasFitnessColumn) {
                return false;
            }

            $today = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');

            return $this->connection->table('fleet_vehicles')
                ->where('id', $vehicleId)
                ->whereNotNull('unfit_since')
                ->whereDate('unfit_since', '<=', $today)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
