<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Application\Command\UpdateVehicleProfile\UpdateVehicleProfile;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de un vehículo desde el admin.
 *
 * Despacha dos comandos por el CommandBus: ChangeVehicleStatus (estado operativo) y UpdateVehicleProfile
 * (ficha técnica y fotos de los cuatro lados). Los datos de registro (placa, capacidad, nodo base,
 * documentos embebidos) quedan deshabilitados en el formulario (no hay comando que los modifique).
 * Desde la cabecera también se pueden registrar documentos del vehículo (SOAT, inspección técnica, etc.).
 */
final class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            OwnerDocuments::registerAction('vehicle'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $commandBus = app(CommandBus::class);

        $commandBus->dispatch(new ChangeVehicleStatus(
            vehicleId: (string) $record->getKey(),
            status: (string) $data['status'],
        ));

        $commandBus->dispatch(new UpdateVehicleProfile(
            vehicleId: (string) $record->getKey(),
            make: $this->blankToNull($data['make'] ?? null),
            model: $this->blankToNull($data['model'] ?? null),
            modelYear: $this->intOrNull($data['model_year'] ?? null),
            vin: $this->blankToNull($data['vin'] ?? null),
            engineNumber: $this->blankToNull($data['engine_number'] ?? null),
            color: $this->blankToNull($data['color'] ?? null),
            photoFront: $this->normalizeFilePath($data['photo_front'] ?? null),
            photoBack: $this->normalizeFilePath($data['photo_back'] ?? null),
            photoLeft: $this->normalizeFilePath($data['photo_left'] ?? null),
            photoRight: $this->normalizeFilePath($data['photo_right'] ?? null),
        ));

        return $record->refresh();
    }

    /**
     * Normaliza lo que entrega FileUpload (string o lista de un elemento) a un path o null.
     */
    private function normalizeFilePath(mixed $filePath): ?string
    {
        if (is_array($filePath)) {
            $filePath = $filePath[array_key_first($filePath)] ?? null;
        }

        return is_string($filePath) && $filePath !== '' ? $filePath : null;
    }

    /**
     * Convierte en null los valores vacíos o no-string (los campos de ficha técnica son opcionales).
     */
    private function blankToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Convierte el año de modelo a entero o null si viene vacío/no numérico.
     */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
