<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\ApproveSettlement\ApproveSettlement;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Aprueba una liquidación enviada por un transportista (comando ApproveSettlement).
 *
 * El id de la liquidación llega por la ruta.
 */
final class ApproveSettlementController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $settlementId): JsonResponse
    {
        return $this->guard(function () use ($settlementId): JsonResponse {
            $this->commands->dispatch(new ApproveSettlement(settlementId: $settlementId));

            return $this->accepted(['settlement_id' => $settlementId]);
        });
    }
}
