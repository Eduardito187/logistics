<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Dispatch;

use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reacción de NOTIFICACIONES ante un incidente de camión (execution.vehicle_incident_reported) que llega
 * por el outbox. Avisa, por el flujo normal de notificaciones (QueueNotification → cascada de canales):
 *
 * - A cada CLIENTE afectado (los envíos que seguían pendientes): "pedido reprogramado" si el incidente es
 *   MAYOR, o "en camino con retraso" si es MENOR.
 * - Al SUPERVISOR de operaciones: un aviso interno del incidente (anclado al primer envío afectado), con
 *   sus contactos de respaldo desde la config.
 *
 * Es idempotente: antes de encolar comprueba que no exista ya una notificación de ese envío y tipo, así
 * la reentrega at-least-once del outbox no duplica avisos. QueueNotification graba sus propios eventos al
 * outbox, de modo que el envío real sigue siendo asíncrono y transaccional (sin I/O síncrono acá).
 */
final readonly class VehicleIncidentNotificationDispatcher implements MessageDispatcher
{
    public function __construct(
        private NotificationRepository $notifications,
        private CommandBus $commandBus,
        private ?string $supervisorEmail,
        private ?string $supervisorPhone,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.vehicle_incident_reported') {
            return;
        }

        $payload = $message->payload();

        $isMajor = ($payload['severity'] ?? null) === 'MAJOR';

        /** @var mixed $raw */
        $raw = $payload['affected_shipment_ids'] ?? [];
        if (!is_array($raw)) {
            return;
        }

        /** @var list<string> $shipmentIds */
        $shipmentIds = array_values(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $raw),
            static fn (string $id): bool => $id !== '',
        ));

        if ($shipmentIds === []) {
            return;
        }

        // Aviso al cliente: reprogramado (MAYOR) o en camino con retraso (MENOR).
        $customerType = $isMajor ? MessageType::Rescheduled : MessageType::DelayedEnRoute;
        foreach ($shipmentIds as $shipmentId) {
            $this->queueOnce($shipmentId, $customerType);
        }

        // Aviso interno al supervisor, anclado al primer envío afectado del incidente.
        $this->queueOnce(
            $shipmentIds[0],
            MessageType::SupervisorIncident,
            $this->supervisorEmail,
            $this->supervisorPhone,
        );
    }

    /**
     * Encola una notificación del tipo dado para el envío, salvo que ya exista una de ese envío y tipo.
     */
    private function queueOnce(string $shipmentId, MessageType $type, ?string $email = null, ?string $phone = null): void
    {
        if ($this->notifications->existsForShipmentAndType($shipmentId, $type)) {
            return;
        }

        $this->commandBus->dispatch(new QueueNotification(
            $shipmentId,
            $type->value,
            $email,
            $phone,
        ));
    }
}
