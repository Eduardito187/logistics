<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Notifications\Database\Factories\SmsBudgetRecordFactory;

/**
 * Fila de la tabla `notification_sms_budgets`: el contador de SMS enviados por período (mes `YYYY-MM`).
 * La PK es el período. El tope vive en la configuración del package, no acá: esta tabla solo cuenta.
 *
 * @property string $period
 * @property int    $sent
 */
final class SmsBudgetRecord extends Model
{
    /** @use HasFactory<SmsBudgetRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'period';

    protected $table = 'notification_sms_budgets';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent' => 'integer',
        ];
    }

    protected static function newFactory(): SmsBudgetRecordFactory
    {
        return SmsBudgetRecordFactory::new();
    }
}
