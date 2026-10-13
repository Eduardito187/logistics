<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Dispositivo físico de la app interna (P1-AUTH-01).
 *
 * Cada token Sanctum emitido para `/api/v1/app/*` queda ligado a un dispositivo. El
 * `fingerprint` (huella estable que envía la app) identifica al equipo; `user_id` es
 * el binding usuario↔dispositivo vigente. `revoked_at` marca un equipo dado de baja
 * (robo, extravío) y `last_seen_at` registra su última actividad.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int|null    $user_id
 * @property string      $fingerprint
 * @property string|null $platform
 * @property string|null $name
 * @property string|null $push_token
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 */
class Device extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'user_id',
        'fingerprint',
        'platform',
        'name',
        'push_token',
        'last_seen_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // UUID v7 (ordenable por tiempo) como identificador público del dispositivo.
        static::creating(function (Device $device): void {
            if ($device->uuid === null || $device->uuid === '') {
                $device->uuid = (string) Str::uuid7();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tokens de acceso emitidos para este dispositivo.
     *
     * @return HasMany<PersonalAccessToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return !$this->isRevoked();
    }

    /**
     * Marca el dispositivo como visto ahora (última actividad).
     */
    public function touchLastSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->save();
    }

    /**
     * Revoca el dispositivo y borra todos sus tokens.
     */
    public function revoke(): void
    {
        $this->tokens()->delete();
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
