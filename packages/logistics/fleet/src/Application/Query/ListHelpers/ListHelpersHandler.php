<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListHelpers;

use Logistics\Fleet\Application\ReadModel\HelperView;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de ayudantes como proyecciones de lectura.
 *
 * @implements QueryHandler<ListHelpers, list<HelperView>>
 */
final readonly class ListHelpersHandler implements QueryHandler
{
    public function __construct(
        private HelperRepository $helpers,
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

        return array_map(static fn (Helper $helper): HelperView => HelperView::fromDomain($helper), $helpers);
    }
}
