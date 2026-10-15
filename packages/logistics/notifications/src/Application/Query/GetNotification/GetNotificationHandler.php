<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\GetNotification;

use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * @implements QueryHandler<GetNotification, NotificationView|null>
 */
final class GetNotificationHandler implements QueryHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
    ) {}

    public function handle(Query $query): ?NotificationView
    {
        if (!$query instanceof GetNotification) {
            throw NotificationsException::because('GetNotificationHandler solo maneja GetNotification');
        }

        $notification = $this->notifications->findById(NotificationId::fromString($query->notificationId));

        if ($notification === null) {
            return null;
        }

        // Referencia externa humana del envío (lado lectura), junto al UUID crudo de shipment_id.
        $externalId = ShipmentRecord::query()->whereKey($notification->shipmentId())->value('external_id');

        return NotificationView::fromNotification($notification, is_string($externalId) ? $externalId : null);
    }
}
