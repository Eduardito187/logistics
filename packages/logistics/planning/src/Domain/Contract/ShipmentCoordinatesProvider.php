<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\GeoPoint;

/**
 * Puerto que resuelve las coordenadas de entrega de los envíos de un plan (las paradas no guardan
 * coordenadas). El dominio define QUÉ necesita; la implementación lee el contexto de Envíos y vive en
 * Infrastructure.
 */
interface ShipmentCoordinatesProvider
{
    /**
     * Coordenadas por id de envío. Los envíos sin geocodificar (o inexistentes) no aparecen en el mapa.
     *
     * @param  list<string>            $shipmentIds
     * @return array<string, GeoPoint>
     */
    public function coordinatesFor(array $shipmentIds): array;
}
