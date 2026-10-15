<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Query\GetOutboxMessage;

use Logistics\Outbox\Application\ReadModel\OutboxMessageView;
use Logistics\Outbox\Domain\Contract\OutboxReader;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el ReadModel de un mensaje del outbox, o null si no existe.
 *
 * @implements QueryHandler<GetOutboxMessageQuery, OutboxMessageView|null>
 */
final readonly class GetOutboxMessageHandler implements QueryHandler
{
    public function __construct(private OutboxReader $reader) {}

    public function handle(Query $query): ?OutboxMessageView
    {
        assert($query instanceof GetOutboxMessageQuery);

        $message = $this->reader->findById(OutboxMessageId::fromString($query->messageId));

        return $message === null ? null : OutboxMessageView::fromMessage($message);
    }
}
