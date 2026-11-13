<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent de `compensation_cases`. Detalle de infraestructura: nunca cruza a Domain. La PK es un
 * UUID en texto generado por el dominio.
 *
 * @property string                    $id
 * @property string                    $trigger
 * @property string                    $scope
 * @property string|null               $shipment_id
 * @property string|null               $order_reference
 * @property string|null               $source_event_id
 * @property string                    $idempotency_key
 * @property int                       $base_cents
 * @property int                       $order_base_cents
 * @property int                       $nominal_cents
 * @property int                       $effective_cents
 * @property string                    $currency
 * @property array<string, mixed>|null $calculation_trace
 * @property int                       $policy_version
 * @property string                    $approval_level
 * @property string                    $status
 * @property string                    $proposed_by
 * @property string|null               $approved_by
 * @property Carbon|null               $decided_at
 * @property string|null               $reason
 * @property array<int, mixed>|null    $affected_lines
 * @property string|null               $ownership
 * @property string|null               $driver_id
 * @property string|null               $vehicle_id
 * @property string|null               $origin_node_id
 */
final class CompensationCaseRecord extends Model
{
    protected $table = 'compensation_cases';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calculation_trace' => 'array',
            'affected_lines' => 'array',
            'decided_at' => 'datetime',
            'base_cents' => 'integer',
            'order_base_cents' => 'integer',
            'nominal_cents' => 'integer',
            'effective_cents' => 'integer',
            'policy_version' => 'integer',
        ];
    }
}
