<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `execution_delivery_evidence`. PK uuid string (UUID v7 del dominio). Detalle de Infrastructure:
 * el mapeo a/desde el agregado lo hace EloquentDeliveryEvidenceRepository.
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $kind
 * @property string|null $customer_id
 * @property string|null $customer_document_id
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string      $storage_disk
 * @property string      $storage_path
 * @property string      $sha256
 * @property string|null $captured_by_user
 * @property string|null $captured_by_device
 * @property string|null $lat
 * @property string|null $lng
 * @property string      $captured_at
 * @property string|null $idempotency_key
 * @property string      $validation_status
 * @property string|null $validation_detail
 * @property string|null $validated_at
 */
final class DeliveryEvidenceRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_delivery_evidence';

    protected $guarded = [];
}
