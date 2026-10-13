<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Policy;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Parada de CLIENTE con sus coordenadas, tal como la ve la política geográfica de rutas. Las
 * coordenadas las aporta quien conoce el envío; planificación no consulta el contexto de envíos.
 *
 * Solo representa paradas que SÍ entregan a un cliente: los traspasos CD→CD y las paradas sin cliente
 * quedan fuera de esta lista (están exentos de la regla de mismo departamento).
 */
final readonly class ClientStopLocation
{
    private function __construct(
        public string $shipmentId,
        public float $latitude,
        public float $longitude,
    ) {}

    public static function of(string $shipmentId, float $latitude, float $longitude): self
    {
        if (trim($shipmentId) === '') {
            throw PlanningException::because('la parada de cliente necesita un id de envío');
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
