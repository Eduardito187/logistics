<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Application\Command\RegisterDeparture\RegisterDeparture;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\DepartureRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una salida/despacho desde el admin.
 *
 * No persiste con Eloquent: despacha RegisterDeparture al bus CQRS (el dominio genera el id). La carga se
 * arma como forma primitiva {description, weight_kg, items}. Como el comando no devuelve el id, se recupera
 * el record recién creado del vehículo (UUID v7 ordenable: el id más alto es el nuevo) para la redirección.
 */
final class CreateDeparture extends CreateRecord
{
    protected static string $resource = DepartureResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vehicleId = (string) $data['vehicle_id'];

        /** @var list<array{name: string, qty: int}> $items */
        $items = [];
        if (is_array($data['load_items'] ?? null)) {
            foreach ($data['load_items'] as $item) {
                if (is_array($item) && isset($item['name'])) {
                    $items[] = [
                        'name' => (string) $item['name'],
                        'qty' => (int) ($item['qty'] ?? 1),
                    ];
                }
            }
        }

        $load = [
            'description' => isset($data['load_description']) && is_string($data['load_description']) && $data['load_description'] !== ''
                ? $data['load_description']
                : null,
            'weight_kg' => isset($data['load_weight_kg']) && $data['load_weight_kg'] !== ''
                ? (float) $data['load_weight_kg']
                : null,
            'items' => $items,
        ];

        try {
            $this->dispatchDeparture($vehicleId, $data, $load);
        } catch (FleetOpsException $e) {
            // Regla de negocio (p. ej. vehículo no apto por checklist crítico): motivo visible, nada se guarda.
            Notification::make()
                ->title(__('Could not register the departure'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        return DepartureRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * Despacha RegisterDeparture por el CommandBus con los datos del formulario.
     *
     * @param array<string, mixed>                                                                               $data
     * @param array{description: string|null, weight_kg: float|null, items: list<array{name: string, qty: int}>} $load
     */
    private function dispatchDeparture(string $vehicleId, array $data, array $load): void
    {
        app(CommandBus::class)->dispatch(new RegisterDeparture(
            vehicleId: $vehicleId,
            driverId: null,
            helperIds: [],
            destination: isset($data['destination']) && is_string($data['destination']) && $data['destination'] !== '' ? $data['destination'] : null,
            routeRef: isset($data['route_ref']) && is_string($data['route_ref']) && $data['route_ref'] !== '' ? $data['route_ref'] : null,
            load: $load,
            odometerOutKm: isset($data['odometer_out_km']) && $data['odometer_out_km'] !== ''
                ? (int) $data['odometer_out_km']
                : null,
            departedAt: Carbon::parse((string) $data['departed_at'])->toIso8601String(),
            note: isset($data['note']) && is_string($data['note']) && $data['note'] !== '' ? $data['note'] : null,
        ));
    }
}
