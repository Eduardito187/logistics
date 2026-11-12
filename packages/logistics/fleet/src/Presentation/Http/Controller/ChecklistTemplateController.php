<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Query\GetChecklistTemplate\GetChecklistTemplate;
use Logistics\Fleet\Application\Query\GetVehicleFitness\GetVehicleFitness;
use Logistics\Fleet\Application\ReadModel\ChecklistTemplateView;
use Logistics\Fleet\Application\ReadModel\VehicleFitnessView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Plantilla del checklist pre-salida para la app (auditoría #27): los ítems, su criticidad y si exigen
 * foto ya no viven fijos en la app; se administran en el admin y se sirven acá.
 */
final readonly class ChecklistTemplateController
{
    use RespondsWithEnvelope;

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/checklist-template?vehicle_type=&vehicle_id= — plantilla activa.
     *
     * `vehicle_type` elige una plantilla específica (si no hay, la por defecto). Con `vehicle_id` se agrega
     * `vehicle_fitness` (aptitud del vehículo HOY en America/La_Paz) para que la app avise si ya está
     * bloqueado. 404 (RFC 7807) si no hay ninguna plantilla activa.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        /** @var ChecklistTemplateView|null $template */
        $template = $this->queryBus->ask(new GetChecklistTemplate($validated['vehicle_type'] ?? null));

        if ($template === null) {
            return ProblemDetails::fromCode(
                ErrorCode::NotFound,
                'No hay una plantilla de checklist pre-salida activa.',
            )->withInstance($request->getRequestUri())->toResponse($request);
        }

        $data = $template->toArray();

        $vehicleId = $validated['vehicle_id'] ?? null;
        if (is_string($vehicleId) && $vehicleId !== '') {
            $today = (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');

            /** @var VehicleFitnessView|null $fitness */
            $fitness = $this->queryBus->ask(new GetVehicleFitness($vehicleId, $today));
            $data['vehicle_fitness'] = $fitness?->toArray();
        }

        return $this->respondData($data);
    }
}
