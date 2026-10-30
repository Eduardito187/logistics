<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Controller;

use Commerce\Catalog\Application\Query\GetProduct\GetProduct;
use Commerce\Catalog\Application\ReadModel\ProductView;
use Commerce\Catalog\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: devuelve un producto por su identificador (consulta GetProduct). 404 si no existe.
 *
 * Lee vía QueryBus (GetProduct → ProductView|null).
 */
final class GetProductController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            /** @var ProductView|null $product */
            $product = $this->queries->ask(new GetProduct(productId: $id));

            if ($product === null) {
                return $this->error('not_found', 'No product found for the given identifier.', 404);
            }

            return $this->data($product->toArray());
        });
    }
}
