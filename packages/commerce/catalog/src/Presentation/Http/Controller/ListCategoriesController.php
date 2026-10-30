<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Controller;

use Commerce\Catalog\Application\Query\ListCategories\ListCategories;
use Commerce\Catalog\Application\ReadModel\CategoryView;
use Commerce\Catalog\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: lista todas las categorías del catálogo (consulta ListCategories).
 *
 * Lee vía QueryBus (ListCategories → list<CategoryView>).
 */
final class ListCategoriesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(): JsonResponse
    {
        return $this->guard(function (): JsonResponse {
            /** @var list<CategoryView> $views */
            $views = $this->queries->ask(new ListCategories);

            return $this->data(array_map(
                static fn (CategoryView $view): array => $view->toArray(),
                $views,
            ));
        });
    }
}
