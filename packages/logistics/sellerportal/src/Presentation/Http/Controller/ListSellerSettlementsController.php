<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\SellerPortal\Application\Contract\SellerContextResolver;
use Logistics\SellerPortal\Application\Contract\SellerReadPort;
use Logistics\SellerPortal\Application\ReadModel\SellerSettlementView;

/**
 * GET /v1/integrations/seller-portal/settlements — liquidaciones (settlement) del seller autenticado.
 */
final class ListSellerSettlementsController extends SellerPortalController
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
            static fn (SellerSettlementView $view): array => $view->toArray(),
            $this->readPort->settlements($sellerId),
        );

        return $this->collection($sellerId, $data);
    }
}
