<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\GeoPoint;

/**
 * Puerto que resuelve las coordenadas del nodo CD de ORIGEN de las rutas a partir de su id. El dominio
 * define QUÉ necesita (un punto para usar de depósito en la matriz y la geometría); la implementación, que
 * lee el contexto de Red, vive en Infrastructure.
 */
interface PlanningOriginLocator
{
    /**
     * Coordenadas del nodo de origen, o null si no hay id o no se pudo resolver (el backend usa entonces un
     * depósito aproximado por grupo).
     */
    public function locate(?string $originNodeId): ?GeoPoint;
}
