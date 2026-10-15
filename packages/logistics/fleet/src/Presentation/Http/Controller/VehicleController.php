<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Application\Query\ListVehicles\ListVehicles;
use Logistics\Fleet\Application\ReadModel\VehicleView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Endpoints HTTP de vehículos. Habla solo con los buses CQRS; nunca con Infrastructure ni Domain.
 *
 * - index: lectura para la app interna y el tablero (opcionalmente filtra por estado).
 * - store: alta de un vehículo (sincronización de datos maestros desde la puerta de integraciones).
 * - changeStatus: habilitar/inhabilitar o mandar a mantenimiento desde la app interna.
 */
final readonly class VehicleController
{
    use RespondsWithEnvelope;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/vehicles — lista los vehículos de la flota.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:available,in_service,maintenance,inactive'],
        ]);

        /** @var list<VehicleView> $vehicles */
        $vehicles = $this->queryBus->ask(new ListVehicles($validated['status'] ?? null));

        return $this->respondCollection(array_map(
            static fn (VehicleView $view): array => $view->toArray(),
            $vehicles,
        ));
    }

    /**
     * POST /fleet/vehicles — registra un vehículo nuevo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plate' => ['required', 'string', 'max:20'],
            'capacity_kg' => ['required', 'integer', 'min:1'],
            'capacity_m3' => ['required', 'numeric', 'min:0'],
            'home_base_node_id' => ['required', 'uuid'],
            'status' => ['sometimes', 'string', 'in:available,in_service,maintenance,inactive'],
            'documents' => ['sometimes', 'array'],
            'documents.*.type' => ['required_with:documents', 'string', 'max:50'],
            'documents.*.expires_on' => ['required_with:documents', 'date_format:Y-m-d'],
        ]);

        /** @var list<array{type: string, expires_on: string}> $documents */
        $documents = array_values($validated['documents'] ?? []);

        $this->commandBus->dispatch(new RegisterVehicle(
            plate: $validated['plate'],
            capacityKg: (int) $validated['capacity_kg'],
            capacityM3: (float) $validated['capacity_m3'],
            homeBaseNodeId: $validated['home_base_node_id'],
            documents: $documents,
            status: $validated['status'] ?? 'available',
        ));

        return $this->respondAccepted(['plate' => $validated['plate']]);
    }

    /**
     * PATCH /fleet/vehicles/{vehicleId}/status — cambia el estado operativo del vehículo.
     */
    public function changeStatus(Request $request, string $vehicleId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:available,in_service,maintenance,inactive'],
        ]);

        $this->commandBus->dispatch(new ChangeVehicleStatus(
            vehicleId: $vehicleId,
            status: $validated['status'],
        ));

        return $this->respondAccepted([
            'vehicle_id' => $vehicleId,
            'new_status' => $validated['status'],
        ], 200);
    }
}
