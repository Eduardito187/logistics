<?php

declare(strict_types=1);

namespace App\Models;

use App\Providers\AuthServiceProvider;
use DateTimeInterface;
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
     * Ventana mínima entre dos escrituras de `last_used_at`. Sanctum lo actualiza en CADA request
     * autenticado (un UPDATE por request); con la app sondeando, eso era escritura pura en el camino
     * caliente. Un minuto de resolución alcanza para auditoría y limpieza de tokens ociosos.
     */
    public const int LAST_USED_RESOLUTION_SECONDS = 60;

    /**
     * Omite el guardado cuando lo único que cambió es `last_used_at` y la marca anterior está dentro de
     * la ventana. Cualquier otro cambio (nombre, habilidades, vencimiento, device_id) guarda normal.
     *
     * @param array<string, mixed> $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && array_keys($this->getDirty()) === ['last_used_at']) {
            $previous = $this->getOriginal('last_used_at');
            if ($previous instanceof DateTimeInterface
                && now()->subSeconds(self::LAST_USED_RESOLUTION_SECONDS)->lessThan($previous)) {
                // Se descarta el cambio en memoria para que el modelo no quede "sucio".
                $this->setAttribute('last_used_at', $previous);
                $this->syncOriginalAttribute('last_used_at');

                return true;
            }
        }

        return parent::save($options);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
