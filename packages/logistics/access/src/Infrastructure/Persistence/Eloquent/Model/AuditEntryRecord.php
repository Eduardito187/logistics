<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\AuditEntryFactory;

/**
 * Modelo Eloquent de la tabla `access_audit_log`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. No usa los timestamps de
 * Laravel ($timestamps = false): el único tiempo relevante es `occurred_at`, que fija el reloj del dominio.
 *
 * @property string               $id
 * @property string|null          $actor_id
 * @property string               $actor_label
 * @property string               $action
 * @property string               $subject_type
 * @property string|null          $subject_id
 * @property array<string, mixed> $context
 * @property string|null          $ip_address
 * @property DateTimeInterface    $occurred_at
 */
final class AuditEntryRecord extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    protected $table = 'access_audit_log';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver AuditEntryFactory).
     */
    protected static function newFactory(): AuditEntryFactory
    {
        return AuditEntryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
