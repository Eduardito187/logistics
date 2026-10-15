<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Contract;

use Logistics\Tracking\Domain\Model\TrackingPushSubscription;
use Logistics\Tracking\Domain\ValueObject\PushSubscriptionId;

/**
 * Puerto de persistencia de las suscripciones Web Push de la página pública de rastreo.
 *
 * save() es un upsert por endpoint (clave natural de la suscripción del navegador): re-suscribir el
 * mismo navegador refresca la fila en vez de duplicarla.
 */
interface PushSubscriptionRepository
{
    public function nextIdentity(): PushSubscriptionId;

    public function save(TrackingPushSubscription $subscription): void;
}
