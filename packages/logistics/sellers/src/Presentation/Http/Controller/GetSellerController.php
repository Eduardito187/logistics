<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Sellers\Application\Query\GetSeller\GetSeller;
use Logistics\Sellers\Application\ReadModel\SellerView;
use Logistics\Sellers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve un seller por su id (consulta GetSeller), o 404 si no existe.
 */
final class GetSellerController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $sellerId): JsonResponse
    {
        return $this->guard(function () use ($sellerId): JsonResponse {
            /** @var SellerView|null $seller */
            $seller = $this->queries->ask(new GetSeller(sellerId: $sellerId));

            if ($seller === null) {
                return $this->error('not_found', 'Seller not found.', 404);
            }

            return $this->data($seller->toArray());
        });
    }
}
