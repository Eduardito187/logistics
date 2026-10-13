<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Endpoints HTTP de tripulaciones (chofer + ayudantes + vehículo para una fecha).
 *
 * - store: arma la tripulación del día desde la app interna (supervisor de patio).
 */
final readonly class CrewController
{
    use RespondsWithEnvelope;

    public function __construct(
        private CommandBus $commandBus,
    ) {}

    /**
     * POST /fleet/crews — arma una tripulación para una fecha.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'uuid'],
            'helper_ids' => ['sometimes', 'array'],
            'helper_ids.*' => ['uuid'],
            'vehicle_id' => ['required', 'uuid'],
            'assembled_for' => ['required', 'date_format:Y-m-d'],
        ]);

        /** @var list<string> $helperIds */
        $helperIds = array_values($validated['helper_ids'] ?? []);

        $this->commandBus->dispatch(new AssembleCrew(
            driverId: $validated['driver_id'],
            helperIds: $helperIds,
            vehicleId: $validated['vehicle_id'],
            assembledFor: $validated['assembled_for'],
        ));

        return $this->respondAccepted([
            'driver_id' => $validated['driver_id'],
            'vehicle_id' => $validated['vehicle_id'],
            'assembled_for' => $validated['assembled_for'],
        ]);
    }
}
