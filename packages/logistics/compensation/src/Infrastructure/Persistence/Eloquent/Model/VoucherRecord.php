<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de `compensation_vouchers`. Detalle de infraestructura. PK UUID en texto.
 *
 * @property string      $id
 * @property string      $case_id
 * @property string      $code
 * @property int         $amount_cents
 * @property string      $currency
 * @property string|null $holder_email_hash
 * @property string|null $holder_email
 * @property \Illuminate\Support\Carbon $expires_at
 * @property string      $status
 * @property string|null $magento_rule_id
 * @property string      $magento_sync_status
 * @property \Illuminate\Support\Carbon|null $held_until
 * @property string|null $held_by_channel
 * @property \Illuminate\Support\Carbon|null $redeemed_at
 * @property string|null $redeemed_channel
 * @property string|null $redeemed_sale_ref
 */
final class VoucherRecord extends Model
{
    protected $table = 'compensation_vouchers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'expires_at' => 'datetime',
            'held_until' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }
}
