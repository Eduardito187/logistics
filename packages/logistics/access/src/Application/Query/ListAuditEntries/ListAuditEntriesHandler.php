<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListAuditEntries;

use Logistics\Access\Application\ReadModel\AuditEntryView;
use Logistics\Access\Domain\Contract\AuditLogRepository;
use Logistics\Access\Domain\Model\AuditEntry;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las entradas de auditoría recientes como proyecciones de lectura.
 *
 * @implements QueryHandler<ListAuditEntries, list<AuditEntryView>>
 */
final readonly class ListAuditEntriesHandler implements QueryHandler
{
    public function __construct(
        private AuditLogRepository $log,
    ) {}

    /**
     * @return list<AuditEntryView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListAuditEntries);

        $entries = $this->log->recent($query->limit, $query->action, $query->subjectType);

        return array_map(
            static fn (AuditEntry $entry): AuditEntryView => AuditEntryView::fromDomain($entry),
            $entries,
        );
    }
}
