<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Settlement\Application\Command\ConfirmSettlement\ConfirmSettlement;
use Logistics\Settlement\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Confirma una liquidación de seller en borrador (comando ConfirmSettlement). El id llega por la ruta.
 */
final class ConfirmSettlementController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $settlementId): JsonResponse
    {
        return $this->guard(function () use ($settlementId): JsonResponse {
            $this->commands->dispatch(new ConfirmSettlement(settlementId: $settlementId));

            return $this->accepted(['settlement_id' => $settlementId]);
        });
    }
}
