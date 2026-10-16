<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Inbound\Application\Command\LabelReceivedUnits\LabelReceivedUnits;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Inbound\Presentation\Http\Request\LabelReceivedUnitsRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Etiqueta las unidades aceptadas de la recepción de un ASN (POST .../label): punto de convergencia.
 * 202 de acuse.
 */
final class LabelReceivedUnitsController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(LabelReceivedUnitsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->commands->dispatch(new LabelReceivedUnits(
                asnId: $request->string('asn_id')->toString(),
            ));

            return $this->accepted(['asn_id' => $request->string('asn_id')->toString()]);
        });
    }
}
