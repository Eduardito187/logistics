<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use DateTimeImmutable;

/**
 * Motor de optimización de rutas detrás de una interfaz propia. El dominio define QUÉ necesita —dada una
 * lista de paradas con coordenadas y una hora de salida, devolver el orden de reparto y la ETA de cada
 * una— sin atarse a ningún proveedor. La integración real (Google Route Optimization primero) es una
 * decisión de Infrastructure.
 */
interface RouteOptimizer
{
    /**
     * Resuelve el orden de reparto y la ETA de cada parada a partir de la salida del depósito.
     *
     * @param list<RouteStop> $stops
     * @return list<OptimizedStop> las paradas en el orden de reparto propuesto, con su ETA
     */
    public function optimize(DateTimeImmutable $departAt, array $stops): array;
}
