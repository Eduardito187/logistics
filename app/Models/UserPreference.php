<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Preferencias del usuario de la app interna (una fila por usuario). Es preferencia de interfaz, no dato
 * de negocio: vive como modelo Eloquent simple en la capa de app, igual que las notas del cockpit.
 *
 * `preferences` es el mapa clave→valor; `key_updated_at` el mapa clave→ISO-8601 del último cambio
 * aceptado de esa clave (resolución last-write-wins por clave, ver UserPreferenceSchema).
 *
 * @property int                   $id
 * @property int                   $user_id
 * @property array<string, mixed>  $preferences
 * @property array<string, string> $key_updated_at
 * @property Carbon|null           $created_at
 * @property Carbon|null           $updated_at
 */
class UserPreference extends Model
{
    protected $table = 'user_preferences';

    /** @var list<string> */
    protected $fillable = ['user_id', 'preferences', 'key_updated_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'preferences' => '{}',
        'key_updated_at' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferences' => 'array',
            'key_updated_at' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
