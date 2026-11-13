<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\ProjectExecutionEvent;

use DateTimeImmutable;
use Exception;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\Contract\CustomerContactReader;
use Logistics\Tracking\Application\Contract\RatingRequestStore;
use Logistics\Tracking\Application\Contract\ShipmentOrderReferenceReader;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Throwable;

/**
 * Traduce un evento de ejecución al estado visible y lo aplica sobre el seguimiento del envío.
 *
 * Tolerante por diseño (es una proyección): si el tipo de evento no mapea a un estado visible no hace nada
 * en vez de fallar. Así el relay puede reenviar sin que un evento irrelevante tumbe la proyección.
 *
 * UPSERT: si el envío todavía no tiene fila en tracking (se perdieron `orders.order_placed` /
 * `shipments.shipment_received` con el outbox congelado, o entró fuera del flujo) NO se sale: se abre el
 * seguimiento desde el `order_reference` del envío, se liga y se aplica el estado. Así tanto el flujo en
 * vivo como el reconcile (`logistics:tracking:reconcile-from-execution`) recuperan los envíos sin fila.
 *
 * @implements CommandHandler<ProjectExecutionEvent>
 */
final class ProjectExecutionEventHandler implements CommandHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly RecordDomainEvents $recordEvents,
        private readonly Clock $clock,
        private readonly CustomerContactReader $contacts,
        private readonly RatingRequestStore $ratingRequests,
        private readonly ShipmentOrderReferenceReader $references,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ProjectExecutionEvent) {
            throw TrackingException::because('ProjectExecutionEventHandler solo maneja ProjectExecutionEvent');
        }

        $status = VisibleStatus::fromExecutionEvent($command->executionEventType);
        if ($status === null) {
            // El evento no afecta el estado visible: se ignora.
            return;
        }

        $tracking = $this->trackings->findByShipmentId($command->shipmentId);
        if ($tracking === null) {
            // El envío no tiene fila de seguimiento: se intenta ABRIRLO desde su order_reference. Si no se
            // puede (sin referencia), la proyección es tolerante y no hace nada.
            $tracking = $this->openForShipment($command->shipmentId);
            if ($tracking === null) {
                return;
            }
        }

        // Proyección TOLERANTE: si la transición no aplica desde el estado actual (evento tardío,
        // duplicado o fuera de orden del relay), se saltea en vez de romper.
        if (!$tracking->canApply($status)) {
            return;
        }

        // ¿La parada ya estaba resuelta (entregada/fallida)? Si sí, este evento es un reenvío y no debe
        // volver a avanzar la cascada (evita el doble descuento en reintentos).
        $wasResolved = $this->isStopResolved($tracking->status());

        // El momento del hecho (p. ej. la entrega) fija delivered_at; ver ShipmentTracking::applyStatus().
        $tracking->applyStatus($status, $this->occurredAt($command->occurredAt));

        $this->trackings->save($tracking);

        $this->recordEvents->record($tracking->releaseEvents(), 'tracking.shipment_tracking');

        // Prerrequisito de la calificación: el evento de entrega no trae el email del cliente, así que se
        // copia del lado lectura de shipments al read model del seguimiento (sin acoplar a execution).
        if ($status === VisibleStatus::Delivered) {
            $this->captureCustomerEmail($command->shipmentId);
        }

        // Una parada se completó (entregada o fallida) POR PRIMERA VEZ. Si el envío tiene ubicación en la
        // ruta, los envíos de atrás en la MISMA ruta quedan una parada más cerca del cliente: se baja su
        // stops_remaining en cascada. Es un ajuste de proyección (no graba eventos), una vez por parada.
        if (!$wasResolved && $this->isStopResolved($status)) {
            $this->advanceLaterStops($tracking->routeId(), $tracking->routePosition()?->value);
        }
    }

    /**
     * UPSERT del seguimiento ausente: abre el seguimiento de un envío que todavía no tiene fila en tracking,
     * lo liga y lo deja LISTO para recibir el estado del evento. Devuelve el agregado, o null si el envío no
     * tiene referencia de orden (sin ella no se puede abrir: tolerante).
     *
     * Se lo lleva hasta EN CURSO (pagado + etiquetado) antes de devolverlo porque un envío que execution
     * reporta cargado/entregado NECESARIAMENTE pasó por esos hitos —se perdieron sus eventos—: PENDIENTE no
     * admite la entrega a propósito (ver {@see VisibleStatus::allowedNext()}), así que abrir en PENDIENTE y
     * aplicar la entrega quedaría saltado por la guardia. EN CURSO es el ancestro común desde el que son
     * admitidos los cinco estados que produce un evento de ejecución (en curso, cargada, en camino,
     * entregado, fallido).
     *
     * Idempotente ante carrera: el índice único de `shipment_id` rechaza un segundo alta; si otro proceso
     * (relay o reconcile) creó el seguimiento entre el chequeo y el save, se reusa al ganador en vez de
     * romper. Un fallo que NO sea esa carrera se relanza para que el relay reintente.
     */
    private function openForShipment(string $shipmentId): ?ShipmentTracking
    {
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
        $tracking->markInProgress();

        try {
            $this->trackings->save($tracking);
        } catch (Throwable $e) {
            $winner = $this->trackings->findByShipmentId($shipmentId);
            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }

        $this->recordEvents->record($tracking->releaseEvents(), 'tracking.shipment_tracking');

        return $tracking;
    }

    /**
     * Interpreta el `occurredAt` del hecho; vacío o con formato inválido cae al reloj (la proyección es
     * tolerante: un dato de fecha malo no debe tumbar el avance del estado).
     */
    private function occurredAt(?string $raw): DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return $this->clock->now();
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return $this->clock->now();
        }
    }

    /**
     * Copia el email del cliente al seguimiento entregado. Sin envío o sin email válido no hace nada: ese
     * envío simplemente no será invitado a calificar.
     */
    private function captureCustomerEmail(string $shipmentId): void
    {
        $email = $this->contacts->forShipment($shipmentId)?->email;
        if ($email === null) {
            return;
        }

        $this->ratingRequests->attachCustomerEmail($shipmentId, $email);
    }

    /** Una parada queda resuelta cuando el envío se entrega o falla el intento. */
    private function isStopResolved(VisibleStatus $status): bool
    {
        return $status === VisibleStatus::Delivered || $status === VisibleStatus::Failed;
    }

    /**
     * Baja en uno las paradas restantes de cada envío de la misma ruta con posición posterior a la que
     * acaba de completarse. Sin ruta/posición conocida no hay cascada.
     */
    private function advanceLaterStops(?string $routeId, ?int $position): void
    {
        if ($routeId === null || $position === null) {
            return;
        }

        foreach ($this->trackings->stopsAheadOnRoute($routeId, $position) as $ahead) {
            $ahead->decrementStops();
            $this->trackings->save($ahead);
        }
    }
}
