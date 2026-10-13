<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\DisputeSettlement\DisputeSettlement;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Request\DisputeSettlementRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Objeta una liquidación por una discrepancia (comando DisputeSettlement).
 *
 * El id de la liquidación llega por la ruta; el motivo por el cuerpo.
 */
final class DisputeSettlementController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(DisputeSettlementRequest $request, string $settlementId): JsonResponse
    {
        return $this->guard(function () use ($request, $settlementId): JsonResponse {
            $this->commands->dispatch(new DisputeSettlement(
                settlementId: $settlementId,
                reason: $request->string('reason')->toString(),
            ));

            return $this->accepted(['settlement_id' => $settlementId]);
        });
    }
}
