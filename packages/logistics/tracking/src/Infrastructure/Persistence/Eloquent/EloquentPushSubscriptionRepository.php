<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Logistics\Tracking\Domain\Contract\PushSubscriptionRepository;
use Logistics\Tracking\Domain\Model\TrackingPushSubscription;
use Logistics\Tracking\Domain\ValueObject\PushSubscriptionId;

/**
 * Implementación Eloquent del repositorio de suscripciones Web Push.
 *
 * save() hace upsert por endpoint (clave natural): re-suscribir el mismo navegador refresca las claves
 * y el envío asociado sin duplicar filas, conservando el id si ya existía.
 */
final class EloquentPushSubscriptionRepository implements PushSubscriptionRepository
{
    public function nextIdentity(): PushSubscriptionId
    {
        return PushSubscriptionId::generate();
    }

    public function save(TrackingPushSubscription $subscription): void
    {
        $sub = $subscription->subscription();

        // Upsert por endpoint SIN tocar la pk: si ya existe la suscripción de ese navegador se refrescan
        // sus claves y el envío asociado, conservando el id original; si no, se crea con el id nuevo.
        $existing = PushSubscriptionRecord::query()
            ->where('endpoint', $sub->endpoint)
            ->first();

        $attributes = [
            'shipment_id' => $subscription->shipmentId(),
            'token' => $subscription->token(),
            'p256dh' => $sub->p256dh,
            'auth' => $sub->auth,
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return;
        }

        PushSubscriptionRecord::query()->create([
            'id' => $subscription->id()->toString(),
            'endpoint' => $sub->endpoint,
            ...$attributes,
        ]);
    }
}
