<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\SubmitSettlement\SubmitSettlement;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Request\SubmitSettlementRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Un transportista envía una liquidación por un corredor y período (comando SubmitSettlement).
 *
 * El id del transportista llega por la ruta; el corredor, el período, el monto (centavos + moneda),
 * la referencia y los documentos por el cuerpo.
 */
final class SubmitSettlementController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(SubmitSettlementRequest $request, string $carrierId): JsonResponse
    {
        return $this->guard(function () use ($request, $carrierId): JsonResponse {
            /** @var list<array{type: string, reference: string}> $documents */
            $documents = array_values($request->array('documents'));

            $this->commands->dispatch(new SubmitSettlement(
                carrierId: $carrierId,
                corridorFrom: $request->string('corridor_from')->toString(),
                corridorTo: $request->string('corridor_to')->toString(),
                corridorType: $request->string('corridor_type')->toString(),
                period: $request->string('period')->toString(),
                amountCents: $request->integer('amount_cents'),
                currency: $request->string('currency')->toString(),
                reference: $request->string('reference')->toString(),
                documents: $documents,
            ));

            return $this->accepted(['carrier_id' => $carrierId]);
        });
    }
}
