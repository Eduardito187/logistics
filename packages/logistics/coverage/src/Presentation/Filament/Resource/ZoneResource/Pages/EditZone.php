<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource\ZoneResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Coverage\Application\Command\UpdateZoneRules;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de una zona de cobertura.
 *
 * Las reglas (CoverageRule) se guardan a través del comando UpdateZoneRules en el bus CQRS, para que
 * pasen por las invariantes del dominio (ZoneRules::fromArray) y reescriban el jsonb de forma
 * controlada preservando la geometría. Los campos propios del registro maestro (nombre, área padre,
 * activo) se persisten sobre el ancla Eloquent, que no tiene comando dedicado.
 */
final class EditZone extends EditRecord
{
    protected static string $resource = ZoneResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof ZoneRecord);

        /** @var array<string, mixed> $rules */
        $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];

        // 1) Reglas por el bus: el handler valida y persiste normalizando el jsonb.
        app(CommandBus::class)->dispatch(new UpdateZoneRules((string) $record->getKey(), $rules));

        // 2) Columnas maestras sin comando dedicado: se guardan sobre el registro ancla.
        $record->fill([
            'name' => $data['name'] ?? $record->name,
            'parent_area_id' => $data['parent_area_id'] ?? null,
            'active' => (bool) ($data['active'] ?? $record->active),
        ]);
        $record->save();

        // Refresca las reglas normalizadas por el dominio para que el formulario las refleje.
        return $record->refresh();
    }
}
