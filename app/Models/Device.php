<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Middleware\EnsureDeviceToken;
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
 * @property string|null $session_bucket
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 */
class Device extends Model
{
    /** Bucket de sesión: una app móvil (android+ios). */
    public const string BUCKET_APP = 'app';

    /** Bucket de sesión: cliente web/escritorio. */
    public const string BUCKET_WEB = 'web';

    /** Motivo de revocación por desalojo: entró una sesión nueva del mismo bucket (1-por-plataforma). */
    public const string REVOKED_REASON_SUPERSEDED = 'session_superseded';

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'user_id',
        'fingerprint',
        'platform',
        'session_bucket',
        'name',
        'push_token',
        'last_seen_at',
        'revoked_at',
        'revoked_reason',
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

    /**
     * Deriva el bucket de sesión (`web` | `app`) a partir del `platform` del dispositivo, con override
     * opcional por `client_kind` explícito del login. Regla: android/ios/desconocido = `app`; solo
     * web/desktop/browser = `web`. Así "un móvil" y "un web" pueden coexistir, pero dos del mismo tipo no.
     */
    public static function resolveBucket(?string $platform, ?string $clientKind = null): string
    {
        $kind = is_string($clientKind) ? strtolower(trim($clientKind)) : '';
        if ($kind === self::BUCKET_WEB || $kind === self::BUCKET_APP) {
            return $kind;
        }

        $normalized = is_string($platform) ? strtolower(trim($platform)) : '';

        return in_array($normalized, ['web', 'desktop', 'browser'], true) ? self::BUCKET_WEB : self::BUCKET_APP;
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
     * Ventana mínima entre dos escrituras de `last_seen_at`. La guarda de la puerta app lo toca en cada
     * request; sin ventana eran un UPDATE por request por equipo (la app sondea seguido), escritura pura
     * en el camino caliente. Un minuto de resolución alcanza para "última actividad".
     */
    public const int LAST_SEEN_RESOLUTION_SECONDS = 60;

    /**
     * Marca el dispositivo como visto ahora (última actividad), a lo sumo una vez por ventana.
     */
    public function touchLastSeen(): void
    {
        $now = now();
        if ($this->last_seen_at !== null
            && $this->last_seen_at->greaterThan($now->copy()->subSeconds(self::LAST_SEEN_RESOLUTION_SECONDS))) {
            return;
        }

        $this->forceFill(['last_seen_at' => $now])->save();
    }

    /**
     * Revoca el dispositivo y borra todos sus tokens.
     *
     * Invalida además el snapshot cacheado del equipo en la puerta app (M-11): aunque borrar los tokens
     * ya corta el acceso vía `auth:sanctum`, se olvida el snapshot para que ningún estado "vigente"
     * previo sobreviva en cache durante su TTL.
     */
    public function revoke(): void
    {
        $this->tokens()->delete();
        $this->forceFill(['revoked_at' => now()])->save();

        EnsureDeviceToken::forgetDevice($this->getKey());
    }

    /**
     * Desaloja el dispositivo porque entró una sesión nueva del MISMO bucket (1-por-plataforma).
     *
     * A diferencia de {@see revoke()}, NO borra los tokens: los deja vivos a propósito para que la guarda
     * de la puerta app ({@see EnsureDeviceToken}) los intercepte en el próximo request y responda un 401
     * con código distinguible (`session_superseded`), y así la app muestre "iniciaste sesión en otro
     * dispositivo" en vez de un cierre genérico. El token queda inerte igual: el guard corta todo acceso,
     * y expira solo por la expiración de Sanctum. Idempotente si ya estaba revocado.
     */
    public function supersede(): void
    {
        if ($this->isRevoked()) {
            return;
        }

        $this->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => self::REVOKED_REASON_SUPERSEDED,
        ])->save();

        EnsureDeviceToken::forgetDevice($this->getKey());
    }
}
