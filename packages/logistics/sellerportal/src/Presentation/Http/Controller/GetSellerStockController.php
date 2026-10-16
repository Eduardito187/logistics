<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\SellerPortal\Application\Contract\SellerContextResolver;
use Logistics\SellerPortal\Application\Contract\SellerReadPort;
use Logistics\SellerPortal\Application\ReadModel\SellerStockView;

/**
 * GET /v1/integrations/seller-portal/stock — stock on-hand (inventory) del seller autenticado.
 */
final class GetSellerStockController extends SellerPortalController
{
    public function __construct(
        SellerContextResolver $resolver,
        private readonly SellerReadPort $readPort,
    ) {
        parent::__construct($resolver);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $sellerId = $this->sellerId($request);

        $data = array_map(
            static fn (SellerStockView $view): array => $view->toArray(),
            $this->readPort->stock($sellerId),
        );

        return $this->collection($sellerId, $data);
    }
}
