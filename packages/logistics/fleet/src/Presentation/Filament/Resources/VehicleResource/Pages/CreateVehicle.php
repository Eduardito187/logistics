<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un vehículo desde el admin.
 *
 * No persiste con Eloquent: despacha RegisterVehicle por el CommandBus (el id UUID lo genera el
 * dominio). Luego relee el registro por su placa (única) para que Filament muestre el resultado.
 */
final class CreateVehicle extends CreateRecord
{
    protected static string $resource = VehicleResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<array{type: string, expires_on: string}> $documents */
        $documents = array_values($data['documents'] ?? []);

        app(CommandBus::class)->dispatch(new RegisterVehicle(
            plate: (string) $data['plate'],
            capacityKg: (int) $data['capacity_kg'],
            capacityM3: (float) $data['capacity_m3'],
            homeBaseNodeId: (string) $data['home_base_node_id'],
            documents: $documents,
            status: (string) ($data['status'] ?? 'available'),
        ));

        return VehicleModel::query()->where('plate', $data['plate'])->firstOrFail();
    }
}
