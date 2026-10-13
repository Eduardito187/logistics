<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Parada a optimizar: un envío con sus coordenadas. Es la entrada del motor de rutas.
 *
 * Las coordenadas llegan desde afuera (quien conoce el envío); el contexto de planificación no las
 * guarda ni consulta el contexto de envíos: referencia al envío solo por id string.
 */
final readonly class RouteStop
{
    private function __construct(
        public string $shipmentId,
        public float $latitude,
        public float $longitude,
    ) {}

    public static function of(string $shipmentId, float $latitude, float $longitude): self
    {
        if (trim($shipmentId) === '') {
            throw PlanningException::because('la parada a optimizar necesita un id de envío');
        }
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw PlanningException::because("latitud fuera de rango (-90..90): {$latitude}");
        }
        if ($longitude < -180.0 || $longitude > 180.0) {
            throw PlanningException::because("longitud fuera de rango (-180..180): {$longitude}");
        }

        return new self(trim($shipmentId), $latitude, $longitude);
    }
}
