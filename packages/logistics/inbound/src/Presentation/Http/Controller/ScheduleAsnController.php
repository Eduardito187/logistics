<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Inbound\Application\Command\ScheduleAsn\ScheduleAsn;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Inbound\Presentation\Http\Request\ScheduleAsnRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Agenda la cita de un ASN (PUT .../{asnId}/schedule): reserva el cupo de andén vía capacity. 202 de acuse.
 */
final class ScheduleAsnController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(ScheduleAsnRequest $request, string $asnId): JsonResponse
    {
        return $this->guard(function () use ($request, $asnId): JsonResponse {
            $this->commands->dispatch(new ScheduleAsn(
                asnId: $asnId,
                appointmentDate: $request->string('appointment_date')->toString(),
                startTime: $request->string('start_time')->toString(),
                endTime: $request->string('end_time')->toString(),
            ));

            return $this->accepted(['asn_id' => $asnId]);
        });
    }
}
