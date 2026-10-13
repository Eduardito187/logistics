<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotificationsByShipment;

use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

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

        return array_map(
            static fn (Notification $notification): NotificationView => NotificationView::fromNotification($notification),
            $this->notifications->findByShipment($query->shipmentId),
        );
    }
}
