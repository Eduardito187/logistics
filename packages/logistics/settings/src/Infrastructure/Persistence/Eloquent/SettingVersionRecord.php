<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de una fila de `settings_versions`.
 *
 * La PK es un UUID string no autoincremental; `value` se castea a arreglo (columna jsonb). No tiene
 * `updated_at`: las filas son inmutables, solo se insertan (de ahí `$timestamps = false`).
 *
 * @property string $id
 * @property string $key
 * @property int $version
 * @property array<string, mixed> $value
 * @property string $effective_from
 * @property string $created_at
 * @property string|null $created_by
 */
final class SettingVersionRecord extends Model
{
    protected $table = 'settings_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'value' => 'array',
        'version' => 'integer',
    ];
}
