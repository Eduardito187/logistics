<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `execution_scan_events`. Append-only: nunca se modifica ni se borra. PK uuid string.
 *
 * El índice único en idempotency_key respalda la idempotencia offline a nivel de base, además de la
 * verificación en el agregado.
 *
 * @property string $id
 * @property string $delivery_id
 * @property string $type
 * @property string $unit_id
 * @property string $lat
 * @property string $lng
 * @property string $device_id
 * @property string $user_id
 * @property string $occurred_at
 * @property string $idempotency_key
 */
final class ScanEventRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_scan_events';

    protected $guarded = [];
}
