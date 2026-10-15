<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Settings\Database\Factories\SettingVersionFactory;

/**
 * Modelo Eloquent de una fila de `settings_versions`.
 *
 * La PK es un UUID string no autoincremental; `value` se castea a arreglo (columna jsonb). No tiene
 * `updated_at`: las filas son inmutables, solo se insertan (de ahí `$timestamps = false`).
 *
 * @property string               $id
 * @property string               $key
 * @property int                  $version
 * @property array<string, mixed> $value
 * @property string               $effective_from
 * @property string               $created_at
 * @property string|null          $created_by
 *
 * @use HasFactory<SettingVersionFactory>
 */
final class SettingVersionRecord extends Model
{
    /** @use HasFactory<SettingVersionFactory> */
    use HasFactory;

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

    /**
     * El package está en dont-discover y la factory vive en su propio namespace, así que se enlaza
     * explícitamente en vez de dejar que Laravel adivine `Database\Factories\...`.
     */
    protected static function newFactory(): SettingVersionFactory
    {
        return SettingVersionFactory::new();
    }
}
