<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Infrastructure\Persistence\Eloquent\SettingVersionRecord;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Publica una nueva versión de una clave de configuración desde el admin.
 *
 * No persiste con Eloquent: despacha el comando SetSetting al bus CQRS, que asigna el id (UUID v7), el
 * número de versión siguiente y la fecha de creación respetando la inmutabilidad del historial. Como el
 * comando no devuelve el id, se recupera la versión recién creada (la mayor para esa clave) para que
 * Filament pueda redirigir a su vista de detalle.
 */
final class CreateSettingVersion extends CreateRecord
{
    protected static string $resource = SettingVersionResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $key = (string) $data['key'];

        /** @var array<string, mixed> $value */
        $value = $this->decodeValue($data['value'] ?? null);

        $effectiveFrom = isset($data['effective_from']) && $data['effective_from'] !== ''
            ? (string) $data['effective_from']
            : null;

        $createdBy = isset($data['created_by']) && $data['created_by'] !== ''
            ? (string) $data['created_by']
            : null;

        app(CommandBus::class)->dispatch(new SetSettingCommand(
            key: $key,
            value: $value,
            effectiveFrom: $effectiveFrom,
            createdBy: $createdBy,
        ));

        // El comando crea la versión de mayor número para la clave: se recupera para la redirección.
        return SettingVersionRecord::query()
            ->where('key', $key)
            ->orderByDesc('version')
            ->firstOrFail();
    }

    /**
     * Decodifica el valor del formulario (JSON string o arreglo ya normalizado) a un arreglo asociativo.
     *
     * @return array<string, mixed>
     */
    private function decodeValue(mixed $raw): array
    {
        if (is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }
}
