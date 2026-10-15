<?php

declare(strict_types=1);

namespace App\Models;

use App\Providers\AuthServiceProvider;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token de acceso Sanctum extendido con el dispositivo al que pertenece.
 *
 * La columna extra `device_id` ata cada token de la app interna a un {@see Device},
 * de modo que al revocar un equipo se borran sus tokens y al rotar el token de un
 * dispositivo no se tocan los de otros.
 *
 * Se registra como modelo de token vía `Sanctum::usePersonalAccessTokenModel()`
 * en {@see AuthServiceProvider}.
 *
 * @property int|null $device_id
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'device_id',
    ];

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
