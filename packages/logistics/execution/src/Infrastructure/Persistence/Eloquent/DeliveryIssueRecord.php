<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `execution_delivery_issues`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado lo
 * hace EloquentDeliveryIssueRepository.
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $type
 * @property string|null $note
 * @property string|null $lat
 * @property string|null $lng
 * @property string|null $photo_path
 * @property string|null $reported_by_user
 * @property string|null $reported_by_device
 * @property string      $occurred_at
 * @property string|null $idempotency_key
 */
final class DeliveryIssueRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_delivery_issues';

    protected $guarded = [];
}
