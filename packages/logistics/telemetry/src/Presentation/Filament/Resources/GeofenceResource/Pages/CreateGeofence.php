<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\DefineGeofence\DefineGeofence;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\GeofenceRecord;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource;

/**
 * Alta de una geocerca desde el admin.
 *
 * No persiste con Eloquent: despacha DefineGeofence al bus CQRS (el dominio genera el id). Como el
 * comando no devuelve el id, se recupera el record recién creado (mismo nombre y tipo, el más reciente)
 * para que Filament pueda redirigir. El polígono llega como lista de {lat,lng} desde el mapa Geoman.
 */
final class CreateGeofence extends CreateRecord
{
    protected static string $resource = GeofenceResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = (string) $data['name'];
        $type = (string) $data['type'];
        $nodeRef = ($data['node_ref'] ?? null) !== null ? (string) $data['node_ref'] : null;

        /** @var list<array{lat: float, lng: float}> $polygon */
        $polygon = $this->normalizePolygon($data['polygon'] ?? []);

        app(CommandBus::class)->dispatch(new DefineGeofence(
            name: $name,
            type: $type,
            polygon: $polygon,
            nodeRef: $nodeRef,
        ));

        // El dominio genera el id; se recupera la geocerca recién definida para la redirección.
        return GeofenceRecord::query()
            ->where('name', $name)
            ->where('type', $type)
            ->latest()
            ->firstOrFail();
    }

    /**
     * Normaliza el polígono recibido del mapa a una lista de pares {lat,lng} con floats.
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function normalizePolygon(mixed $polygon): array
    {
        if (!is_array($polygon)) {
            return [];
        }

        $points = [];
        foreach ($polygon as $vertex) {
            if (is_array($vertex) && isset($vertex['lat'], $vertex['lng'])) {
                $points[] = ['lat' => (float) $vertex['lat'], 'lng' => (float) $vertex['lng']];
            }
        }

        return $points;
    }
}
