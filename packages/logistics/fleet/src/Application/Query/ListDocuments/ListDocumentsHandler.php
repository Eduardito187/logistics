<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListDocuments;

use Logistics\Fleet\Application\ReadModel\DocumentView;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Model\FleetDocument;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de documentos de flota como proyecciones de lectura.
 *
 * @implements QueryHandler<ListDocuments, list<DocumentView>>
 */
final readonly class ListDocumentsHandler implements QueryHandler
{
    public function __construct(
        private DocumentRepository $documents,
    ) {}

    /**
     * @return list<DocumentView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListDocuments);

        $documents = $this->documents->all();
        if ($query->ownerType !== null) {
            $documents = array_values(array_filter(
                $documents,
                static fn (FleetDocument $document): bool => $document->ownerType()->value === $query->ownerType,
            ));
        }

        return array_map(static fn (FleetDocument $document): DocumentView => DocumentView::fromDomain($document), $documents);
    }
}
