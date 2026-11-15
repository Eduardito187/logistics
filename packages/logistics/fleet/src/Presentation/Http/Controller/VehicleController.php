<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Execution\Application\Command\DetachShipmentFromRoute\DetachShipmentFromRoute;
use Logistics\Execution\Application\Command\OpenDelivery\OpenDelivery;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Application\Query\ListVehicles\ListVehicles;
use Logistics\Fleet\Application\ReadModel\VehicleView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenance;
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
            // Búsqueda por placa y filtro por nodo base (ciudad/sede del vehículo).
            'q' => ['sometimes', 'nullable', 'string', 'max:60'],
            'node_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        /** @var list<VehicleView> $vehicles */
        $vehicles = $this->queryBus->ask(new ListVehicles($validated['status'] ?? null));

        $items = array_map(static fn (VehicleView $view): array => $view->toArray(), $vehicles);

        $nodeId = $validated['node_id'] ?? null;
        if (is_string($nodeId) && $nodeId !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['home_base_node_id'] ?? null) === $nodeId,
            ));
        }

        return $this->respondSearchable($request, $items, ['plate']);
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

    /**
     * POST /fleet/vehicles/{vehicleId}/send-to-maintenance — «mandar a mantenimiento ahora» en UNA llamada:
     * abre la orden de mantenimiento, deja el vehículo en estado `maintenance` y RESUELVE su ruta del día.
     *
     * Un camión en el taller no puede seguir con pedidos encima, así que sus paradas PENDIENTES no quedan
     * colgadas:
     *  - con `reassign_to_vehicle_id`, se pasan a ese camión (su ruta abierta, o una nueva) con su entrega;
     *  - sin él, se liberan de la ruta y vuelven a quedar por asignar (la respuesta dice cuáles son).
     * Las paradas ya atendidas no se tocan. Todo se aplica junto: si algo falla (p. ej. el camión destino no
     * está apto) no queda nada a medias.
     */
    public function sendToMaintenance(Request $request, string $vehicleId): JsonResponse
    {
        /** @var array{type?: string, workshop?: string|null, note?: string|null, odometer_km?: int|null, scheduled_at?: string|null, reassign_to_vehicle_id?: string|null} $validated */
        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:preventive,corrective,inspection'],
            'workshop' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'odometer_km' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            // Camión que toma las paradas pendientes de la ruta del día (opcional).
            'reassign_to_vehicle_id' => ['sometimes', 'nullable', 'uuid', 'different:vehicleId'],
        ]);

        $target = isset($validated['reassign_to_vehicle_id']) && $validated['reassign_to_vehicle_id'] !== ''
            ? (string) $validated['reassign_to_vehicle_id']
            : null;
        if ($target === $vehicleId) {
            $target = null;
        }

        $pending = $this->pendingStopsOf($vehicleId);

        DB::transaction(function () use ($vehicleId, $validated, $target, $pending): void {
            foreach ($pending as $stop) {
                if ($target !== null) {
                    $this->commandBus->dispatch(new AttachShipmentToRoute(
                        vehicleId: $target,
                        shipmentId: $stop['shipment_id'],
                        orderReference: $stop['order_reference'],
                        customerName: $stop['customer_name'],
                        address: $stop['address'],
                    ));
                    $this->commandBus->dispatch(new OpenDelivery($stop['shipment_id'], $target));
                }
                $this->commandBus->dispatch(new DetachShipmentFromRoute($vehicleId, $stop['shipment_id']));
            }

            $this->commandBus->dispatch(new ScheduleMaintenance(
                vehicleId: $vehicleId,
                type: $validated['type'] ?? 'corrective',
                odometerKm: isset($validated['odometer_km']) ? (int) $validated['odometer_km'] : null,
                workshop: $validated['workshop'] ?? null,
                scheduledAt: isset($validated['scheduled_at']) && $validated['scheduled_at'] !== ''
                    ? (string) $validated['scheduled_at']
                    : now()->toAtomString(),
                note: $validated['note'] ?? null,
            ));
            $this->commandBus->dispatch(new ChangeVehicleStatus(vehicleId: $vehicleId, status: 'maintenance'));
        });

        $orders = array_map(static fn (array $stop): array => [
            'shipment_id' => $stop['shipment_id'],
            'order_reference' => $stop['order_reference'],
            'customer_name' => $stop['customer_name'],
        ], $pending);

        return $this->respondAccepted([
            'vehicle_id' => $vehicleId,
            'new_status' => 'maintenance',
            // Qué pasó con las paradas pendientes de su ruta: a qué camión fueron, o cuáles quedan por asignar.
            'route' => [
                'pending_stops' => count($pending),
                'reassigned_to_vehicle_id' => $pending !== [] ? $target : null,
                'reassigned' => $target !== null ? $orders : [],
                'pending_reassignment' => $target === null ? $orders : [],
            ],
        ], 200);
    }

    /**
     * Paradas PENDIENTES de la ruta abierta del vehículo. Tolerante: lista vacía si no tiene ruta o si la
     * consulta falla.
     *
     * @return list<array{shipment_id: string, order_reference: string|null, customer_name: string|null, address: string|null}>
     */
    private function pendingStopsOf(string $vehicleId): array
    {
        try {
            $route = $this->queryBus->ask(new GetDriverRoute($vehicleId));
        } catch (\Throwable) {
            return [];
        }

        if (!$route instanceof DriverRouteView) {
            return [];
        }

        $pending = [];
        foreach ($route->stops as $stop) {
            if (strtoupper($stop->status) !== 'PENDING') {
                continue;
            }
            $pending[] = [
                'shipment_id' => $stop->shipmentId,
                'order_reference' => $stop->orderReference,
                'customer_name' => $stop->customerName,
                'address' => $stop->address,
            ];
        }

        return $pending;
    }
}
