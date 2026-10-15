<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\IntegrationTokenFactory;

/**
 * Modelo Eloquent de la tabla `access_integration_tokens`. Es detalle de infraestructura: nunca cruza a
 * Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. El hash del secreto se
 * oculta ($hidden) para que nunca viaje en serializaciones; el secreto en claro no se persiste jamás.
 *
 * @property string                 $id
 * @property string                 $name
 * @property string                 $token_prefix
 * @property string                 $token_hash
 * @property list<string>           $permission_keys
 * @property string                 $status
 * @property DateTimeInterface|null $expires_at
 * @property DateTimeInterface|null $last_used_at
 * @property string|null            $created_by
 */
final class IntegrationTokenRecord extends Model
{
    /** @use HasFactory<IntegrationTokenFactory> */
    use HasFactory;

    protected $table = 'access_integration_tokens';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver IntegrationTokenFactory).
     */
    protected static function newFactory(): IntegrationTokenFactory
    {
        return IntegrationTokenFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permission_keys' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
