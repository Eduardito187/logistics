<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\SettlementFactory;

/**
 * Modelo Eloquent de la tabla `carrier_settlements`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string                                       $id
 * @property string                                       $carrier_id
 * @property string                                       $corridor_from
 * @property string                                       $corridor_to
 * @property string                                       $corridor_type
 * @property string                                       $period
 * @property int                                          $amount_cents
 * @property string                                       $currency
 * @property string                                       $reference
 * @property string                                       $status
 * @property string|null                                  $dispute_reason
 * @property string|null                                  $payment_reference
 * @property list<array{type: string, reference: string}> $documents
 */
final class SettlementRecord extends Model
{
    /** @use HasFactory<SettlementFactory> */
    use HasFactory;

    protected $table = 'carrier_settlements';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver SettlementFactory).
     */
    protected static function newFactory(): SettlementFactory
    {
        return SettlementFactory::new();
    }

    /**
     * Transportista al que pertenece la liquidación.
     *
     * Relación de solo lectura para que el admin muestre el nombre del transportista en vez del UUID;
     * las mutaciones siguen pasando por los comandos del ciclo de liquidación.
     *
     * @return BelongsTo<CarrierRecord, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(CarrierRecord::class, 'carrier_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'documents' => 'array',
        ];
    }
}
