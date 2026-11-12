<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetChecklistTemplate;

use Logistics\Fleet\Application\ReadModel\ChecklistTemplateView;
use Logistics\Fleet\Domain\Contract\ChecklistTemplateRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve la plantilla activa (la del tipo si existe; si no, la por defecto) como proyección de lectura.
 *
 * @implements QueryHandler<GetChecklistTemplate, ChecklistTemplateView|null>
 */
final readonly class GetChecklistTemplateHandler implements QueryHandler
{
    public function __construct(
        private ChecklistTemplateRepository $templates,
    ) {}

    public function handle(Query $query): ?ChecklistTemplateView
    {
        assert($query instanceof GetChecklistTemplate);

        $template = $this->templates->activeFor($query->vehicleType);

        return $template !== null ? ChecklistTemplateView::fromDomain($template) : null;
    }
}
