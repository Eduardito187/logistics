<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Infrastructure\ReadSide\PublicTrackingCache;
use Throwable;

/**
 * Decorador del repositorio de seguimiento: ÚNICO punto de invalidación del caché de lectura público.
 *
 * Cualquier cambio del seguimiento pasa por `save()` (abrir, ligar envío, avanzar estado, proyección
 * de execution, ubicar en ruta, cascada de paradas, posición del camión), así que invalidar acá cubre
 * a los comandos y a los reactores del outbox sin regar llamadas por los handlers.
 *
 * Qué invalida: se compara la foto guardada contra el agregado recién grabado y se olvida SOLO si
 * cambió lo que el cliente debe ver de inmediato (estado, paradas restantes, posición en la ruta) o si
 * la entrada era negativa/vencida. La posición del camión y la ETA NO invalidan: llegan cada pocos
 * segundos por envío y las cubre el TTL fresco; invalidar en cada ping anularía el caché.
 *
 * Cuándo: en el acto (el proceso que escribe relee lo suyo) y, si hay una transacción abierta, otra
 * vez después del COMMIT. Sin esa segunda pasada, un lector concurrente podría repoblar el caché con el
 * estado previo al commit y dejarlo "fresco" toda la ventana.
 *
 * Un fallo del caché nunca tumba la escritura: se reporta y el TTL acota la antigüedad.
 */
final readonly class CacheInvalidatingTrackingRepository implements TrackingRepository
{
    public function __construct(
        private TrackingRepository $inner,
        private PublicTrackingCache $cache,
    ) {}

    public function nextIdentity(): TrackingId
    {
        return $this->inner->nextIdentity();
    }

    public function save(ShipmentTracking $tracking): void
    {
        $this->inner->save($tracking);

        if (!$this->cache->enabled()) {
            return;
        }

        $this->invalidateIfChanged($tracking);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($tracking): void {
                $this->invalidateIfChanged($tracking);
            });
        }
    }

    public function findById(TrackingId $id): ?ShipmentTracking
    {
        return $this->inner->findById($id);
    }

    public function findByToken(TrackingToken $token): ?ShipmentTracking
    {
        return $this->inner->findByToken($token);
    }

    public function findByShipmentId(string $shipmentId): ?ShipmentTracking
    {
        return $this->inner->findByShipmentId($shipmentId);
    }

    public function findByOrderReference(string $orderReference): ?ShipmentTracking
    {
        return $this->inner->findByOrderReference($orderReference);
    }

    public function findAllByOrderReference(string $orderReference): array
    {
        return $this->inner->findAllByOrderReference($orderReference);
    }

    public function stopsAheadOnRoute(string $routeId, int $position): array
    {
        return $this->inner->stopsAheadOnRoute($routeId, $position);
    }

    /**
     * Olvida la foto del token si ya no representa lo que el cliente debe ver. Sin entrada guardada no
     * hay nada que olvidar: la próxima lectura la arma desde la base.
     */
    private function invalidateIfChanged(ShipmentTracking $tracking): void
    {
        $token = $tracking->token()->toString();

        try {
            $cached = $this->cache->peek($token);
            if ($cached === null) {
                return;
            }

            if (!$this->stillRepresents(PublicTrackingSnapshot::fromArray($cached), $tracking)) {
                $this->cache->forget($token);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * ¿La foto guardada sigue mostrando el estado visible del agregado? Una foto ilegible, negativa o
     * vencida nunca lo representa (se olvida y se rearma).
     */
    private function stillRepresents(?PublicTrackingSnapshot $snapshot, ShipmentTracking $tracking): bool
    {
        $view = $snapshot?->view;
        if ($view === null) {
            return false;
        }

        return $view->status === $tracking->status()->value
            && $view->remainingStops === $tracking->remainingStops()
            && $view->routePosition === $tracking->routePosition()?->value;
    }
}
