<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Controller;

use Commerce\Catalog\Application\Exception\ProductNotFound;
use Commerce\Catalog\Application\Query\GetProductBySku\GetProductBySku;
use Commerce\Catalog\Application\ReadModel\ProductView;
use Commerce\Catalog\Presentation\Http\ApiController;
use Commerce\Catalog\Presentation\Http\Request\SetProductPhysicalSpecRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta integrations: `PUT /catalog/products/{sku}/physical-spec` — Magento/POS define las medidas de
 * un producto por su SKU canónico (no por UUID). Upsert idempotente: reenviar la misma ficha no graba
 * un evento nuevo.
 *
 * 200 con la ficha resultante (incluye el volumen derivado); 404 si el SKU no existe; 422 si la forma o
 * los rangos son inválidos (FormRequest o regla del dominio).
 */
final class SetProductPhysicalSpecController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(SetProductPhysicalSpecRequest $request, string $sku): JsonResponse
    {
        return $this->guard(function () use ($request, $sku): JsonResponse {
            try {
                $this->commands->dispatch($request->toCommand($sku));
            } catch (ProductNotFound $e) {
                return $this->error('not_found', $e->getMessage(), 404);
            }

            /** @var ProductView|null $product */
            $product = $this->queries->ask(new GetProductBySku($sku));
            if ($product === null) {
                return $this->error('not_found', 'No product found for the given SKU.', 404);
            }

            return $this->data([
                'canonical_sku' => $product->canonicalSku,
                'name' => $product->name,
                'physical_spec' => $product->physicalSpec,
            ]);
        });
    }
}
