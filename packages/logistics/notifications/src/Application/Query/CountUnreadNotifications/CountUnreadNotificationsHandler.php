<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\CountUnreadNotifications;

use Logistics\Notifications\Application\Contract\NotificationInboxReader;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<CountUnreadNotifications, int>
 */
final class CountUnreadNotificationsHandler implements QueryHandler
{
    public function __construct(
        private readonly NotificationInboxReader $inbox,
    ) {}

    public function handle(Query $query): int
    {
        if (!$query instanceof CountUnreadNotifications) {
            throw NotificationsException::because('CountUnreadNotificationsHandler solo maneja CountUnreadNotifications');
        }

        return $this->inbox->countUnread($query->userId);
    }
}
