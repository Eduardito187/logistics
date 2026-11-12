<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotifications;

use Logistics\Notifications\Application\Contract\NotificationInboxReader;
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
 * COUNT(*) aparte; enriquece solo los ítems de la página con el id externo del envío, la fecha de
 * creación y el estado de lectura del usuario (consultas en lote, evita N+1).
 *
 * Con `unreadOnly` y usuario, el filtro de no leídas y su total también se resuelven en SQL.
 *
 * @implements QueryHandler<ListNotifications, Page<NotificationView>>
 */
final class ListNotificationsHandler implements QueryHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationInboxReader $inbox,
    ) {}

    /**
     * @return Page<NotificationView>
     */
    public function handle(Query $query): Page
    {
        if (!$query instanceof ListNotifications) {
            throw NotificationsException::because('ListNotificationsHandler solo maneja ListNotifications');
        }

        $userId = $query->userId !== null && trim($query->userId) !== '' ? trim($query->userId) : null;

        if ($query->unreadOnly && $userId !== null) {
            $notifications = $this->inbox->unreadFor($userId, $query->limit, $query->offset);
            $total = $this->inbox->countUnread($userId);
        } else {
            $notifications = $this->notifications->latest($query->limit, $query->offset);
            $total = $this->notifications->count();
        }

        $externalIds = self::resolveExternalIds($notifications);
        $states = $this->inbox->readStates($userId, array_map(
            static fn (Notification $notification): string => $notification->id()->toString(),
            $notifications,
        ));

        $views = array_map(
            static function (Notification $notification) use ($externalIds, $states): NotificationView {
                $state = $states[$notification->id()->toString()] ?? null;

                return NotificationView::fromNotification(
                    $notification,
                    $externalIds[$notification->shipmentId()] ?? null,
                    $state['created_at'] ?? null,
                    $state['read_at'] ?? null,
                );
            },
            $notifications,
        );

        return new Page($views, $total);
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
