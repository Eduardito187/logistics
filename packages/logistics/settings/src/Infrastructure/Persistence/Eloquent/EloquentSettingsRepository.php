<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\SettingVersion;
use Logistics\Settings\Domain\ValueObject\SettingId;
use Logistics\Settings\Domain\ValueObject\SettingKey;

/**
 * Implementación Eloquent del repositorio de versiones de configuración.
 *
 * La concurrencia se protege con el índice único (key, version) de la tabla: si dos requests piden
 * el mismo `nextVersionFor` y ambos insertan, la segunda inserción viola el único y falla. El valor
 * se guarda siempre como arreglo (forma primitiva), nunca el objeto de dominio serializado.
 */
final class EloquentSettingsRepository implements SettingsRepository
{
    public function nextVersionFor(SettingKey $key): int
    {
        /** @var int|null $max */
        $max = SettingVersionRecord::query()
            ->where('key', $key->value)
            ->max('version');

        return ($max ?? 0) + 1;
    }

    public function add(SettingVersion $v): void
    {
        $record = new SettingVersionRecord;
        $record->id = $v->id->toString();
        $record->key = $v->key->value;
        $record->version = $v->version;
        $record->value = $v->value;
        $record->effective_from = $v->effectiveFrom->format(DATE_ATOM);
        $record->created_at = $v->createdAt->format(DATE_ATOM);
        $record->created_by = $v->createdBy;
        $record->save();
    }

    public function currentFor(SettingKey $key, DateTimeImmutable $at): ?SettingVersion
    {
        /** @var SettingVersionRecord|null $record */
        $record = SettingVersionRecord::query()
            ->where('key', $key->value)
            ->where('effective_from', '<=', $at->format(DATE_ATOM))
            ->orderByDesc('version')
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function historyFor(SettingKey $key): array
    {
        /** @var list<SettingVersionRecord> $records */
        $records = SettingVersionRecord::query()
            ->where('key', $key->value)
            ->orderByDesc('version')
            ->get()
            ->all();

        return array_map(fn (SettingVersionRecord $r): SettingVersion => $this->toDomain($r), $records);
    }

    /**
     * Mapea una fila Eloquent a la entidad de dominio.
     */
    private function toDomain(SettingVersionRecord $record): SettingVersion
    {
        /** @var array<string, mixed> $value */
        $value = $record->value;

        return SettingVersion::reconstitute(
            SettingId::fromString($record->id),
            SettingKey::fromString($record->key),
            $record->version,
            $value,
            new DateTimeImmutable($record->effective_from),
            new DateTimeImmutable($record->created_at),
            $record->created_by,
        );
    }
}
