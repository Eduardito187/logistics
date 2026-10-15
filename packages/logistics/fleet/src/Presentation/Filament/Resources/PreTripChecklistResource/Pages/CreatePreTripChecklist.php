<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\ConductPreTripChecklist\ConductPreTripChecklist;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\PreTripInspectionModel;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un checklist pre-salida desde el admin.
 *
 * Convierte las filas del repeater (item + checked) a un mapa nombre => verificado, despacha
 * ConductPreTripChecklist por el CommandBus (el UUID lo genera el dominio) y relee la inspección recién
 * creada (vehículo + chofer + fecha, la más reciente) para que Filament muestre el resultado.
 */
final class CreatePreTripChecklist extends CreateRecord
{
    protected static string $resource = PreTripChecklistResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<array{item: string, checked: bool}> $rows */
        $rows = array_values($data['checklist_items'] ?? []);

        $items = [];
        foreach ($rows as $row) {
            $items[(string) $row['item']] = (bool) ($row['checked'] ?? false);
        }

        app(CommandBus::class)->dispatch(new ConductPreTripChecklist(
            vehicleId: (string) $data['vehicle_id'],
            driverId: (string) $data['driver_id'],
            items: $items,
            performedOn: (string) $data['performed_on'],
            notes: isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
        ));

        return PreTripInspectionModel::query()
            ->where('vehicle_id', $data['vehicle_id'])
            ->where('driver_id', $data['driver_id'])
            ->where('performed_on', $data['performed_on'])
            ->latest()
            ->firstOrFail();
    }
}
