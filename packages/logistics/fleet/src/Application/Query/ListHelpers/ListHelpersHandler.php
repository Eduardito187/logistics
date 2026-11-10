<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListHelpers;

use Logistics\Fleet\Application\ReadModel\HelperView;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de ayudantes como proyecciones de lectura.
 *
 * Los documentos de cada ayudante se adjuntan desde la tabla polimórfica `fleet_documents` (owner_type=
 * 'helper'), no del jsonb inline del agregado (A5). Se arma un mapa dueño→documentos de una sola pasada
 * para no hacer N+1.
 *
 * @implements QueryHandler<ListHelpers, list<HelperView>>
 */
final readonly class ListHelpersHandler implements QueryHandler
{
    public function __construct(
        private HelperRepository $helpers,
        private DocumentRepository $documents,
    ) {}

    /**
     * @return list<HelperView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListHelpers);

        $helpers = $this->helpers->all();
        if ($query->onlyActive === true) {
            $helpers = array_values(array_filter(
                $helpers,
                static fn (Helper $helper): bool => $helper->isActive(),
            ));
        }

        // Mapa owner_id → documentos (primitivos) de los ayudantes, de una sola pasada por fleet_documents.
        $docsByHelper = [];
        foreach ($this->documents->all() as $document) {
            if ($document->ownerType()->value !== 'helper') {
                continue;
            }
            $docsByHelper[$document->ownerId()][] = $document->document()->toArray();
        }

        return array_map(
            static function (Helper $helper) use ($docsByHelper): HelperView {
                /** @var list<array{type: string, expires_on: string}> $docs */
                $docs = $docsByHelper[$helper->id()->toString()] ?? [];

                return HelperView::fromDomain($helper, $docs);
            },
            $helpers,
        );
    }
}
