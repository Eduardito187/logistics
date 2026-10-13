<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\ExpenseFactory;

/**
 * Modelo Eloquent de la tabla `fleetops_expenses`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $vehicle_id
 * @property string      $category
 * @property int         $amount_cents
 * @property string      $currency
 * @property string|null $description
 * @property Carbon      $incurred_at
 * @property string|null $note
 */
final class ExpenseRecord extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected $table = 'fleetops_expenses';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver ExpenseFactory).
     */
    protected static function newFactory(): ExpenseFactory
    {
        return ExpenseFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'incurred_at' => 'datetime',
        ];
    }
}
