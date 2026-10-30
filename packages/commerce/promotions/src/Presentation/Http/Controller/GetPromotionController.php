<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Controller;

use Commerce\Promotions\Application\Query\GetPromotion\GetPromotion;
use Commerce\Promotions\Application\ReadModel\PromotionView;
use Commerce\Promotions\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta integrations: consulta una promoción por su id (consulta GetPromotion). 404 si no existe.
 *
 * Lee vía QueryBus (GetPromotion → PromotionView|null). El id viene por la ruta.
 */
final class GetPromotionController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            /** @var PromotionView|null $view */
            $view = $this->queries->ask(new GetPromotion(promotionId: $id));

            if ($view === null) {
                return $this->error('not_found', 'No promotion found for the given id.', 404);
            }

            return $this->data($view->toArray());
        });
    }
}
