<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Logistics\Planning\Domain\Contract\ShipmentCoordinatesProvider;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Throwable;

/**
 * Lee lat/lng de los envíos (contexto Envíos, solo lectura) en una sola consulta y los traduce a GeoPoint.
 * Omite los envíos sin coordenadas numéricas o fuera de rango; degrada a vacío si la lectura falla.
 */
final readonly class EloquentShipmentCoordinatesProvider implements ShipmentCoordinatesProvider
{
    public function coordinatesFor(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        try {
            $records = ShipmentRecord::query()
                ->whereIn('id', array_values(array_unique($shipmentIds)))
                ->get(['id', 'lat', 'lng']);
        } catch (Throwable) {
            return [];
        }

        $points = [];
        foreach ($records as $record) {
            if (!is_numeric($record->lat) || !is_numeric($record->lng)) {
                continue;
            }

            try {
                $points[(string) $record->id] = GeoPoint::of((float) $record->lat, (float) $record->lng);
            } catch (Throwable) {
                // Coordenada fuera de rango: se omite como si no estuviera geocodificada.
            }
        }

        return $points;
    }
}
