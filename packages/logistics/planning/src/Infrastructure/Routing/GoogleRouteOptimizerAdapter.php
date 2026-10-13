<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Routing;

use DateTimeImmutable;
use Logistics\Planning\Domain\Contract\OptimizedStop;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\ValueObject\Eta;

/**
 * STUB del motor de rutas. Devuelve las paradas en el MISMO orden en que llegan y les asigna una ETA
 * secuencial (un tramo fijo por parada desde la salida). NO optimiza nada: es un marcador de posición.
 *
 * La integración real con Google Route Optimization (orden óptimo y ETA por tráfico/distancia) se hará
 * más adelante, detrás de esta misma interfaz, sin tocar el dominio ni la capa de aplicación.
 */
final class GoogleRouteOptimizerAdapter implements RouteOptimizer
{
    /** Minutos de viaje asumidos entre paradas consecutivas mientras esto sea un stub. */
    private const MINUTES_PER_LEG = 20;

    public function optimize(DateTimeImmutable $departAt, array $stops): array
    {
        $optimized = [];
        $minutes = 0;
        foreach ($stops as $stop) {
            $minutes += self::MINUTES_PER_LEG;
            $arrivalAt = $departAt->modify("+{$minutes} minutes");
            $optimized[] = new OptimizedStop($stop->shipmentId, Eta::at($arrivalAt, $minutes));
        }

        return $optimized;
    }
}
