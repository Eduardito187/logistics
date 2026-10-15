<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RedrawGeofence\RedrawGeofence;
use Logistics\Telemetry\Application\Command\RenameGeofence\RenameGeofence;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\GeofenceRecord;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource;

/**
 * Edición de una geocerca desde el admin.
 *
 * No escribe el record: despacha al bus CQRS RenameGeofence (si cambió el nombre) y RedrawGeofence (si
 * cambió el polígono dibujado en el mapa Geoman). El tipo y el nodo no se editan aquí (no existe un
 * comando para cambiarlos). Devuelve el record recargado para que Filament refresque la vista.
 */
final class EditGeofence extends EditRecord
{
    protected static string $resource = GeofenceResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $geofenceId = (string) $record->getKey();
        $commandBus = app(CommandBus::class);

        // Renombrar solo si el nombre cambió.
        $name = (string) ($data['name'] ?? '');
        if ($name !== '' && $name !== (string) $record->getAttribute('name')) {
            $commandBus->dispatch(new RenameGeofence(
                geofenceId: $geofenceId,
                name: $name,
            ));
        }

        // Redibujar solo si el polígono cambió.
        /** @var list<array{lat: float, lng: float}> $polygon */
        $polygon = $this->normalizePolygon($data['polygon'] ?? []);
        /** @var mixed $currentRaw */
        $currentRaw = $record->getAttribute('polygon');
        $current = $this->normalizePolygon($currentRaw);
        if ($polygon !== [] && $polygon !== $current) {
            $commandBus->dispatch(new RedrawGeofence(
                geofenceId: $geofenceId,
                polygon: $polygon,
            ));
        }

        // Record recargado desde la proyección de lectura ya actualizada por los handlers.
        return GeofenceRecord::query()->findOrFail($geofenceId);
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
