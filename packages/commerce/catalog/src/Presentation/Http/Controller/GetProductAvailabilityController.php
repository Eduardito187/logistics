<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Controller;

use Commerce\Catalog\Application\Contract\ProductAvailabilityReader;
use Commerce\Catalog\Application\Query\GetProduct\GetProduct;
use Commerce\Catalog\Application\ReadModel\ProductView;
use Commerce\Catalog\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: devuelve la disponibilidad (available-to-promise) de un producto por su identificador.
 *
 * Obtiene el producto vía GetProduct (para resolver su `canonical_sku`) y consulta el stock disponible a
 * través del puerto de lectura `ProductAvailabilityReader` (lectura cruzada read-only contra inventory).
 * Endpoint aditivo, separado del detalle de producto: no modifica ProductView. 404 si el producto no existe.
 */
final class GetProductAvailabilityController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly ProductAvailabilityReader $availability,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        return $this->guard(function () use ($id): JsonResponse {
            /** @var ProductView|null $product */
            $product = $this->queries->ask(new GetProduct(productId: $id));

            if ($product === null) {
                return $this->error('not_found', 'No product found for the given identifier.', 404);
            }

            return $this->data([
                'canonical_sku' => $product->canonicalSku,
                'available' => $this->availability->availableForSku($product->canonicalSku),
            ]);
        });
    }
}
