<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\SetHelperAvailability\SetHelperAvailability;
use Logistics\Fleet\Application\Command\UpdateHelperProfile\UpdateHelperProfile;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de un ayudante desde el admin.
 *
 * Despacha dos comandos: SetHelperAvailability para el estado (activo / baja) y UpdateHelperProfile para
 * los datos personales (foto, CI, nacimiento, teléfono secundario, dirección, contacto de emergencia y
 * referencias). Nombre, teléfono primario y documentos de alta no se modifican (no hay comando). Desde la
 * cabecera también se pueden registrar documentos del ayudante.
 */
final class EditHelper extends EditRecord
{
    protected static string $resource = HelperResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            OwnerDocuments::registerAction('helper'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $helperId = (string) $record->getKey();
        $bus = app(CommandBus::class);

        $bus->dispatch(new SetHelperAvailability(
            helperId: $helperId,
            active: (bool) ($data['active'] ?? true),
        ));

        $bus->dispatch(new UpdateHelperProfile(
            helperId: $helperId,
            photoPath: $this->normalizeFilePath($data['photo_path'] ?? null),
            birthDate: $this->blankToNull($data['birth_date'] ?? null),
            nationalId: $this->blankToNull($data['national_id'] ?? null),
            phoneSecondary: $this->blankToNull($data['phone_secondary'] ?? null),
            address: $this->blankToNull($data['address'] ?? null),
            emergencyContactName: $this->blankToNull($data['emergency_contact_name'] ?? null),
            emergencyContactPhone: $this->blankToNull($data['emergency_contact_phone'] ?? null),
            references: $this->normalizeReferences($data['references'] ?? []),
        ));

        return $record->refresh();
    }

    /**
     * Normaliza la lista de referencias del Repeater a la forma que espera el comando.
     *
     *
     * @return list<array{name: string, relationship?: string|null, phone?: string|null, note?: string|null}>
     */
    private function normalizeReferences(mixed $references): array
    {
        if (!is_array($references)) {
            return [];
        }

        $normalized = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $name = isset($reference['name']) ? trim((string) $reference['name']) : '';
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'relationship' => $this->blankToNull($reference['relationship'] ?? null),
                'phone' => $this->blankToNull($reference['phone'] ?? null),
                'note' => $this->blankToNull($reference['note'] ?? null),
            ];
        }

        return $normalized;
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
     * Convierte en null los valores vacíos o no-string (los campos personales son opcionales).
     */
    private function blankToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
