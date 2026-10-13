<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `sap_stock_transfers`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * El índice único sobre `event_id` respalda la idempotencia: una recepción (event_id) encola un solo
 * traspaso. Detalle de Infrastructure; el mapeo a/desde el agregado lo hace StockTransferMapper.
 *
 * @property string $id
 * @property string $event_id
 * @property string $shipment_ref
 * @property string $material
 * @property string $center
 * @property string $warehouse
 * @property int $quantity
 * @property string $status
 * @property string|null $last_error
 */
final class StockTransferRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sap_stock_transfers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
