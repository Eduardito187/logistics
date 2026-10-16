<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Inbound\Application\Command\RecordArrival\RecordArrival;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Inbound\Presentation\Http\Request\RecordArrivalRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra la llegada del camión del seller (POST .../arrival). 202 de acuse.
 */
final class RecordArrivalController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RecordArrivalRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $carrier = $request->string('carrier_reference')->toString();

            $this->commands->dispatch(new RecordArrival(
                asnId: $request->string('asn_id')->toString(),
                carrierReference: $carrier !== '' ? $carrier : null,
            ));

            return $this->accepted(['asn_id' => $request->string('asn_id')->toString()]);
        });
    }
}
