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
 * Wiring por eventos de dominio (P1-NOT): cuando el relay del outbox procesa un hecho de otro contexto
 * (Envíos, Ejecución, Tracking), este dispatcher traduce el evento al tipo de mensaje al cliente que le
 * corresponde y encola la notificación con el comando QueueNotification.
 *
 * Es idempotente: antes de encolar comprueba que no exista ya una notificación de ese envío y tipo, así
 * la reentrega at-least-once del outbox no duplica avisos. La propia QueueNotification graba sus eventos
 * al outbox (RecordDomainEvents), así que el envío real sigue siendo asíncrono y transaccional.
 *
 * Solo atiende los eventos del mapa; cualquier otro lo ignora, para componerse con los dispatchers de
 * otros contextos sin pisarlos. El integrador enruta los prefijos `shipments.`, `execution.` y
 * `tracking.` a este dispatcher en la config del outbox (ver README del package).
 */
final readonly class DomainEventNotificationDispatcher implements MessageDispatcher
{
    /**
     * Mapa evento de dominio → tipo de mensaje al cliente. Un evento sin entrada no genera notificación.
     *
     * @var array<string, MessageType>
     */
    private const EVENT_MAP = [
        'shipments.shipment_received' => MessageType::OrderReceived,
        'execution.shipment_prepared' => MessageType::InPreparation,
        'execution.node_reception_recorded' => MessageType::AtDistributionCenter,
        'execution.shipment_loaded' => MessageType::Loaded,
        'execution.shipment_delivered' => MessageType::Delivered,
        'execution.delivery_failed' => MessageType::Failed,
        'tracking.status_changed' => MessageType::EnRoute,
    ];

    public function __construct(
        private NotificationRepository $notifications,
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        $messageType = self::EVENT_MAP[$message->eventName()] ?? null;
        if ($messageType === null) {
            return;
        }

        $payload = $message->payload();
        $shipmentId = $payload['shipment_id'] ?? null;
        if (!is_string($shipmentId) || $shipmentId === '') {
            return;
        }

        // Idempotencia: no re-encolar el mismo hito del mismo envío ante reentregas del outbox.
        if ($this->notifications->existsForShipmentAndType($shipmentId, $messageType)) {
            return;
        }

        $email = isset($payload['customer_email']) && is_string($payload['customer_email']) ? $payload['customer_email'] : null;
        $phone = isset($payload['customer_phone']) && is_string($payload['customer_phone']) ? $payload['customer_phone'] : null;
        $telegramChatId = isset($payload['telegram_chat_id']) && is_string($payload['telegram_chat_id']) ? $payload['telegram_chat_id'] : null;

        $this->commandBus->dispatch(new QueueNotification(
            $shipmentId,
            $messageType->value,
            $email,
            $phone,
            $telegramChatId,
        ));
    }
}
