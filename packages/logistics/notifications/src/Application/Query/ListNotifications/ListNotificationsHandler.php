<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotifications;

use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * @implements QueryHandler<ListNotifications, list<NotificationView>>
 */
final class ListNotificationsHandler implements QueryHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
    ) {}

    /**
     * @return list<NotificationView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListNotifications) {
            throw NotificationsException::because('ListNotificationsHandler solo maneja ListNotifications');
        }

        $notifications = $this->notifications->latest($query->limit);

        $externalIds = self::resolveExternalIds($notifications);

        return array_map(
            static fn (Notification $notification): NotificationView => NotificationView::fromNotification(
                $notification,
                $externalIds[$notification->shipmentId()] ?? null,
            ),
            $notifications,
        );
    }

    /**
     * Resuelve en lote las referencias externas de los envíos (evita N+1 en listados).
     *
     * @param  list<Notification>    $notifications
     * @return array<string, string>
     */
    private static function resolveExternalIds(array $notifications): array
    {
        $shipmentIds = array_values(array_unique(array_map(
            static fn (Notification $notification): string => $notification->shipmentId(),
            $notifications,
        )));

        if ($shipmentIds === []) {
            return [];
        }

        $externalIds = [];
        foreach (ShipmentRecord::query()->whereIn('id', $shipmentIds)->get() as $shipment) {
            $externalIds[$shipment->id] = $shipment->external_id;
        }

        return $externalIds;
    }
}
