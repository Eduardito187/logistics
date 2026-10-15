<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Service;

/**
 * Servicio de dominio PURO (sin Laravel, sin Eloquent) del modelo de costos de la flota propia.
 *
 * A partir del costo ya capturado en fleetops (combustible + mantenimiento + gastos) deriva el costo
 * por kilómetro, lo reparte proporcionalmente por distancia (p. ej. a una salida o corredor) y lo divide
 * por entrega. Todo en centavos (enteros) para no arrastrar errores de redondeo de float.
 *
 * No conoce persistencia ni fechas: recibe primitivos y devuelve primitivos. Es la pieza que hace que el
 * "costo por km" por vehículo se convierta en costo por ruta/entrega/corredor (ROADMAP 3-01).
 */
final readonly class CostAllocator
{
    /**
     * Costo por kilómetro en centavos: costo total / km recorridos. Null si no hay km (no se puede
     * dividir por cero): el costo por km queda indefinido, no cero.
     */
    public function costPerKmCents(int $totalCostCents, int $kilometers): ?int
    {
        if ($kilometers <= 0) {
            return null;
        }

        return (int) round($totalCostCents / $kilometers);
    }

    /**
     * Costo de un tramo/ruta a partir del costo por km del vehículo y los km de ese tramo. Si el costo
     * por km es desconocido (null) o la distancia es no positiva, el costo atribuible es 0.
     */
    public function routeCostCents(?int $costPerKmCents, int $routeKilometers): int
    {
        if ($costPerKmCents === null || $routeKilometers <= 0) {
            return 0;
        }

        return (int) round($costPerKmCents * $routeKilometers);
    }

    /**
     * Reparte un costo total proporcionalmente a la porción de una magnitud (p. ej. costo total de un
     * vehículo repartido a un corredor según sus km). Devuelve 0 si el total de la magnitud es no positivo.
     */
    public function allocateByShare(int $totalCostCents, int $totalUnits, int $shareUnits): int
    {
        if ($totalUnits <= 0 || $shareUnits <= 0) {
            return 0;
        }

        return (int) round($totalCostCents * ($shareUnits / $totalUnits));
    }

    /**
     * Costo por entrega en centavos: costo (de la ruta/corredor) / número de entregas. Null si no hay
     * entregas: el costo por entrega queda indefinido, no cero.
     */
    public function costPerDeliveryCents(int $costCents, int $deliveries): ?int
    {
        if ($deliveries <= 0) {
            return null;
        }

        return (int) round($costCents / $deliveries);
    }
}
