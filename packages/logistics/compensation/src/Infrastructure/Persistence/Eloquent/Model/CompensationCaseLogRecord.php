<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de `compensation_case_log` (bitácora inmutable, solo INSERT). Sin updated_at.
 *
 * @property string      $id
 * @property string      $case_id
 * @property string|null $voucher_id
 * @property string      $action
 * @property string      $actor
 * @property array<string, mixed>|null $payload
 * @property \Illuminate\Support\Carbon $created_at
 */
final class CompensationCaseLogRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'compensation_case_log';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
