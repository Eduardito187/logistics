<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Query\GetSettlement\GetSettlement;
use Logistics\Carriers\Application\ReadModel\SettlementView;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve una liquidación por su id (consulta GetSettlement). Responde 404 si no existe.
 */
final class GetSettlementController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $settlementId): JsonResponse
    {
        return $this->guard(function () use ($settlementId): JsonResponse {
            /** @var SettlementView|null $settlement */
            $settlement = $this->queries->ask(new GetSettlement(settlementId: $settlementId));

            if ($settlement === null) {
                return $this->error('settlement_not_found', 'No settlement found for the given id.', 404);
            }

            return $this->data($settlement->toArray());
        });
    }
}
