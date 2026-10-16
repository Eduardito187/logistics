<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Sellers\Application\Command\UpsertSellerSku\UpsertSellerSku;
use Logistics\Sellers\Presentation\Http\ApiController;
use Logistics\Sellers\Presentation\Http\Request\UpsertSellerSkuRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Da de alta o actualiza un SKU del catálogo de un seller despachando UpsertSellerSku al bus CQRS.
 */
final class UpsertSellerSkuController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(UpsertSellerSkuRequest $request, string $sellerId): JsonResponse
    {
        return $this->guard(function () use ($request, $sellerId): JsonResponse {
            $sapMaterial = $request->string('sap_material')->toString();

            $this->commands->dispatch(new UpsertSellerSku(
                sellerId: $sellerId,
                sku: $request->string('sku')->toString(),
                sapMaterial: $sapMaterial !== '' ? $sapMaterial : null,
            ));

            return $this->accepted(['seller_id' => $sellerId, 'sku' => $request->string('sku')->toString()]);
        });
    }
}
