<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fila de la tabla tracking_push_subscriptions. Detalle de infraestructura: el dominio no lo conoce;
 * lo usa el repositorio Eloquent para mapear hacia/desde el agregado TrackingPushSubscription.
 *
 * La pk es el UUID de la suscripción (string, no autoincremental). endpoint es único (clave natural).
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $token
 * @property string      $endpoint
 * @property string      $p256dh
 * @property string      $auth
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PushSubscriptionRecord extends Model
{
    public $incrementing = false;

    protected $table = 'tracking_push_subscriptions';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'shipment_id',
        'token',
        'endpoint',
        'p256dh',
        'auth',
    ];
}
