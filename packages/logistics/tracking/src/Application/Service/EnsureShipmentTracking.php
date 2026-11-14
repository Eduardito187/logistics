<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Service;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Tracking\Application\Contract\ShipmentOrderReferenceReader;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Throwable;

/**
 * Garantiza que un envío tenga seguimiento: devuelve el que ya existe o lo abre desde la referencia de
 * orden del envío, ligado y en «procesando».
 *
 * Por qué hace falta: el seguimiento se abre con `orders.order_placed` / `shipments.shipment_received`. Un
 * envío que entró por otra puerta (ingesta, datos de prueba) o cuyos eventos se perdieron queda sin fila,
 * y el pedido se ve «sin seguimiento» hasta que se entrega. Cualquier proyección que necesite el
 * seguimiento de un envío vivo lo pide por acá en vez de saltearlo.
 *
 * Idempotente ante carrera: si otro proceso lo abrió entre el chequeo y el alta, se reusa ese.
 */
final readonly class EnsureShipmentTracking
{
    public function __construct(
        private TrackingRepository $trackings,
        private ShipmentOrderReferenceReader $references,
        private RecordDomainEvents $recordEvents,
    ) {}

    /**
     * Seguimiento del envío, abierto si faltaba. Null si el envío no deja una referencia de orden utilizable.
     * Con `$silent` (reconciliación) el alta no graba eventos: no reemite avisos por algo que ya pasó.
     */
    public function forShipment(string $shipmentId, bool $silent = false): ?ShipmentTracking
    {
        $existing = $this->trackings->findByShipmentId($shipmentId);
        if ($existing !== null) {
            return $existing;
        }

        $orderReference = $this->references->orderReferenceForShipment($shipmentId);
        if ($orderReference === null || trim($orderReference) === '') {
            return null;
        }

        $tracking = ShipmentTracking::open(
            $this->trackings->nextIdentity(),
            $orderReference,
            TrackingToken::generate(),
        );
        $tracking->bindShipment($shipmentId);
        $tracking->markProcessing();

        try {
            $this->trackings->save($tracking);
        } catch (Throwable $e) {
            $winner = $this->trackings->findByShipmentId($shipmentId);
            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }

        $events = $tracking->releaseEvents();
        if (!$silent) {
            $this->recordEvents->record($events, 'tracking.shipment_tracking');
        }

        return $tracking;
    }
}
