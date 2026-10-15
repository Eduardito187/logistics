<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\MarkSettlementPaid\MarkSettlementPaid;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Request\MarkSettlementPaidRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Marca una liquidación aprobada como pagada (comando MarkSettlementPaid).
 *
 * El id de la liquidación llega por la ruta; la referencia del pago por el cuerpo.
 */
final class MarkSettlementPaidController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(MarkSettlementPaidRequest $request, string $settlementId): JsonResponse
    {
        return $this->guard(function () use ($request, $settlementId): JsonResponse {
            $this->commands->dispatch(new MarkSettlementPaid(
                settlementId: $settlementId,
                paymentReference: $request->string('payment_reference')->toString(),
            ));

            return $this->accepted(['settlement_id' => $settlementId]);
        });
    }
}
