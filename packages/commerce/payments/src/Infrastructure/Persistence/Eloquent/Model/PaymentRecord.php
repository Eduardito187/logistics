<?php

declare(strict_types=1);

namespace Commerce\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `commerce_payments`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $order_id
 * @property int         $amount_cents
 * @property string      $currency
 * @property string      $method
 * @property string      $status
 * @property string|null $gateway_ref
 */
final class PaymentRecord extends Model
{
    protected $table = 'commerce_payments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
