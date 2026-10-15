<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotificationsByShipment;

use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * @implements QueryHandler<ListNotificationsByShipment, list<NotificationView>>
 */
final class ListNotificationsByShipmentHandler implements QueryHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
    ) {}

    /**
     * @return list<NotificationView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListNotificationsByShipment) {
            throw NotificationsException::because('ListNotificationsByShipmentHandler solo maneja ListNotificationsByShipment');
        }

        // Todas las notificaciones son del mismo envío: resolvemos su referencia externa una sola vez.
        $externalId = ShipmentRecord::query()->whereKey($query->shipmentId)->value('external_id');
        $externalId = is_string($externalId) ? $externalId : null;

        return array_map(
            static fn (Notification $notification): NotificationView => NotificationView::fromNotification(
                $notification,
                $externalId,
            ),
            $this->notifications->findByShipment($query->shipmentId),
        );
    }
}
