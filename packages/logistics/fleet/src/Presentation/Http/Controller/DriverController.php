<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriver;
use Logistics\Fleet\Application\Query\ListDrivers\ListDrivers;
use Logistics\Fleet\Application\ReadModel\DriverView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Endpoints HTTP de choferes. Habla solo con los buses CQRS.
 *
 * - index: lectura (opcionalmente solo activos) para la app interna y el tablero.
 * - store: alta de un chofer (sincronización de datos maestros de RR. HH. vía integraciones).
 */
final readonly class DriverController
{
    use RespondsWithEnvelope;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/drivers — lista los choferes de la flota.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'only_active' => ['sometimes', 'boolean'],
        ]);

        $onlyActive = array_key_exists('only_active', $validated)
            ? $request->boolean('only_active')
            : null;

        /** @var list<DriverView> $drivers */
        $drivers = $this->queryBus->ask(new ListDrivers($onlyActive));

        return $this->respondCollection(array_map(
            static fn (DriverView $view): array => $view->toArray(),
            $drivers,
        ));
    }

    /**
     * POST /fleet/drivers — registra un chofer nuevo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'license_number' => ['required', 'string', 'max:50'],
            'license_expiry' => ['required', 'date_format:Y-m-d'],
            'enabled_vehicle_ids' => ['sometimes', 'array'],
            'enabled_vehicle_ids.*' => ['uuid'],
            'active' => ['sometimes', 'boolean'],
            'license_document_type' => ['sometimes', 'string', 'max:50'],
        ]);

        /** @var list<string> $enabledVehicleIds */
        $enabledVehicleIds = array_values($validated['enabled_vehicle_ids'] ?? []);

        $this->commandBus->dispatch(new RegisterDriver(
            name: $validated['name'],
            licenseNumber: $validated['license_number'],
            licenseExpiry: $validated['license_expiry'],
            enabledVehicleIds: $enabledVehicleIds,
            active: $request->boolean('active', true),
            licenseDocumentType: $validated['license_document_type'] ?? 'driver_license',
        ));

        return $this->respondAccepted(['license_number' => $validated['license_number']]);
    }
}
