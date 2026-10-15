<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Query\ListOutboxMessages;

use Logistics\Outbox\Application\ReadModel\OutboxMessagePage;
use Logistics\Outbox\Application\ReadModel\OutboxMessageView;
use Logistics\Outbox\Domain\Contract\OutboxReader;
use Logistics\Outbox\Domain\Exception\OutboxException;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una página de mensajes del outbox proyectados a ReadModel.
 *
 * @implements QueryHandler<ListOutboxMessagesQuery, OutboxMessagePage>
 */
final readonly class ListOutboxMessagesHandler implements QueryHandler
{
    public function __construct(private OutboxReader $reader) {}

    public function handle(Query $query): OutboxMessagePage
    {
        assert($query instanceof ListOutboxMessagesQuery);

        $status = $this->resolveStatus($query->status);
        $page = max(1, $query->page);
        $perPage = max(1, min(200, $query->perPage));
        $offset = ($page - 1) * $perPage;

        $items = array_map(
            static fn (OutboxMessage $message): OutboxMessageView => OutboxMessageView::fromMessage($message),
            $this->reader->list($status, $perPage, $offset),
        );

        return new OutboxMessagePage(
            $items,
            $this->reader->count($status),
            $page,
            $perPage,
        );
    }

    private function resolveStatus(?string $status): ?OutboxStatus
    {
        if ($status === null) {
            return null;
        }

        return OutboxStatus::tryFrom($status)
            ?? throw OutboxException::because("estado de outbox desconocido: {$status}");
    }
}
