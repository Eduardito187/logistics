<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `sap_notifications` (avisos fire-and-forget a SAP). PK uuid string no incremental
 * (UUID v7 del dominio). Detalle de Infrastructure; el mapeo a/desde el agregado lo hace
 * SapNotificationMapper. El panel Filament la usa solo para leer/filtrar.
 *
 * @property string               $id
 * @property string               $event_id
 * @property string               $event_name
 * @property string|null          $aggregate_id
 * @property array<string, mixed> $payload
 * @property CarbonInterface|null $occurred_at
 * @property string               $status
 * @property int                  $attempts
 * @property string|null          $last_error
 * @property int|null             $response_status
 * @property string|null          $response_body
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class SapNotificationRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sap_notifications';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }
}
