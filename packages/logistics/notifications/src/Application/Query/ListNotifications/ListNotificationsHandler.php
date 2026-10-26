<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotifications;

use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Lista el log global de notificaciones, paginado. Baja LIMIT/OFFSET a SQL y arma el total con un
 * COUNT(*) aparte; enriquece solo los ítems de la página con el id externo del envío (evita N+1).
 *
 * @implements QueryHandler<ListNotifications, Page<NotificationView>>
 */
final class ListNotificationsHandler implements QueryHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
    ) {}

    /**
     * @return Page<NotificationView>
     */
    public function handle(Query $query): Page
    {
        if (!$query instanceof ListNotifications) {
            throw NotificationsException::because('ListNotificationsHandler solo maneja ListNotifications');
        }

        $notifications = $this->notifications->latest($query->limit, $query->offset);

        $externalIds = self::resolveExternalIds($notifications);

        $views = array_map(
            static fn (Notification $notification): NotificationView => NotificationView::fromNotification(
                $notification,
                $externalIds[$notification->shipmentId()] ?? null,
            ),
            $notifications,
        );

        return new Page($views, $this->notifications->count());
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
