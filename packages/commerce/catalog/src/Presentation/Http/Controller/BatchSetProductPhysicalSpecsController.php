<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Controller;

use Commerce\Catalog\Application\Exception\ProductNotFound;
use Commerce\Catalog\Presentation\Http\ApiController;
use Commerce\Catalog\Presentation\Http\Request\BatchSetProductPhysicalSpecsRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Puerta integrations: `POST /catalog/products/physical-specs` — lote de medidas por SKU (carga masiva
 * desde Magento/POS). Cada ítem se despacha como su propio comando (su propia transacción): un SKU
 * inexistente o un valor fuera de rango NO aborta el resto del lote.
 *
 * 200 con el resultado por ítem (`updated` | `not_found` | `invalid`) y el resumen. 422 solo si la forma
 * del lote es inválida (FormRequest).
 */
final class BatchSetProductPhysicalSpecsController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(BatchSetProductPhysicalSpecsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $results = [];
            $summary = ['updated' => 0, 'not_found' => 0, 'invalid' => 0];

            foreach ($request->toCommands() as $command) {
                $sku = (string) $command->sku;

                try {
                    $this->commands->dispatch($command);
                    $results[] = ['sku' => $sku, 'status' => 'updated'];
                    $summary['updated']++;
                } catch (ProductNotFound $e) {
                    $results[] = ['sku' => $sku, 'status' => 'not_found', 'message' => $e->getMessage()];
                    $summary['not_found']++;
                } catch (DomainException $e) {
                    $results[] = ['sku' => $sku, 'status' => 'invalid', 'message' => $e->getMessage()];
                    $summary['invalid']++;
                }
            }

            return $this->data(['results' => $results, 'summary' => $summary]);
        });
    }
}
