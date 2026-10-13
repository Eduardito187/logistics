<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\Factories\OutboxMessageFactory;

/**
 * Registro Eloquent de la tabla outbox_messages.
 *
 * La clave primaria es un UUID (no autoincremental) y los timestamps se manejan por columnas propias
 * (created_at / dispatched_at / available_at), no por el par updated_at/created_at de Eloquent.
 *
 * @property string               $id
 * @property string               $event_id
 * @property string               $aggregate_type
 * @property string               $aggregate_id
 * @property string               $event_name
 * @property array<string, mixed> $payload
 * @property string               $status
 * @property int                  $attempts
 * @property Carbon               $available_at
 * @property Carbon               $created_at
 * @property Carbon|null          $dispatched_at
 * @property string|null          $last_error
 */
final class OutboxMessageRecord extends Model
{
    /** @use HasFactory<OutboxMessageFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'outbox_messages';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Factory del package (autoload PSR-4 bajo src/). Permite seeders y tests con datos realistas.
     */
    protected static function newFactory(): OutboxMessageFactory
    {
        return OutboxMessageFactory::new();
    }

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
