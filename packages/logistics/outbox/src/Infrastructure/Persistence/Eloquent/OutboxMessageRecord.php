<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro Eloquent de la tabla outbox_messages.
 *
 * La clave primaria es un UUID (no autoincremental) y los timestamps se manejan por columnas propias
 * (created_at / dispatched_at / available_at), no por el par updated_at/created_at de Eloquent.
 *
 * @property string $id
 * @property string $event_id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $event_name
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $available_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $dispatched_at
 * @property string|null $last_error
 */
final class OutboxMessageRecord extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'outbox_messages';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'created_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }
}
