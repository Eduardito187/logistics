<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Acción offline de la app que el servidor rechazó de forma definitiva (o que agotó reintentos),
 * reportada por la app con `POST /app/outbox-failures`. Sin datos personales: solo metadatos de la
 * operación; usuario y dispositivo salen del token. Idempotente por `client_id` (ULID del teléfono).
 *
 * @property int         $id
 * @property string      $client_id
 * @property int|null    $user_id
 * @property int|null    $device_id
 * @property string      $queue        'action' | 'photo'
 * @property string      $kind
 * @property string      $reason       'rejected' | 'retries_exhausted' | 'invalid_payload' | 'unknown_kind'
 * @property int|null    $http_status
 * @property string|null $problem_code
 * @property string|null $endpoint
 * @property int         $attempts
 * @property string|null $app_version
 * @property Carbon      $occurred_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 * @property Carbon|null $created_at
 */
class AppOutboxFailure extends Model
{
    /**
     * Colas locales de origen en el teléfono.
     *
     * @var list<string>
     */
    public const array QUEUES = ['action', 'photo'];

    /**
     * Motivos de falla que reporta la app.
     *
     * @var list<string>
     */
    public const array REASONS = ['rejected', 'retries_exhausted', 'invalid_payload', 'unknown_kind'];

    protected $table = 'app_outbox_failures';

    /** @var list<string> */
    protected $fillable = [
        'client_id',
        'user_id',
        'device_id',
        'queue',
        'kind',
        'reason',
        'http_status',
        'problem_code',
        'endpoint',
        'attempts',
        'app_version',
        'occurred_at',
        'resolved_at',
        'resolved_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'attempts' => 'integer',
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Marca la falla como atendida por un actor del admin (idempotente: no pisa una resolución previa).
     */
    public function markResolved(?string $actor): void
    {
        if ($this->isResolved()) {
            return;
        }

        $this->forceFill(['resolved_at' => now(), 'resolved_by' => $actor])->save();
    }
}
