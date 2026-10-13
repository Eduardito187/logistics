<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Presentation\Http\ApiController;
use Logistics\Telemetry\Presentation\Http\Request\RecordVehiclePingsRequest;

/**
 * Ingiere un lote de lecturas GPS de un vehículo despachando el comando RecordVehiclePings.
 *
 * El `source` (app vs integración) lo fija cada subclase según la puerta, no el cuerpo: así el mismo
 * contrato de ingesta sirve para el app interno (batch del dispositivo) y para proveedores GPS externos.
 */
abstract class AbstractRecordVehiclePingsController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    /**
     * Origen de las lecturas: 'app' o 'integration'. Lo define la subclase según su puerta.
     */
    abstract protected function source(): string;

    public function __invoke(RecordVehiclePingsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $driverId = $request->input('driver_id');
            $routeId = $request->input('route_id');

            $this->commands->dispatch(new RecordVehiclePings(
                vehicleId: $request->string('vehicle_id')->toString(),
                driverId: is_string($driverId) && $driverId !== '' ? $driverId : null,
                routeId: is_string($routeId) && $routeId !== '' ? $routeId : null,
                source: $this->source(),
                pings: $this->mapPings($request),
            ));

            return $this->accepted([
                'vehicle_id' => $request->string('vehicle_id')->toString(),
                'source' => $this->source(),
                'count' => count($request->array('pings')),
            ]);
        });
    }

    /**
     * Normaliza el lote de lecturas a la forma primitiva que espera el comando: posición e instante
     * obligatorios, el resto de la telemetría opcional (solo se incluye si vino en la petición).
     *
     * @return list<array{lat: float, lng: float, recorded_at: string, speed_kph?: float, heading?: int, accuracy_m?: float, ignition?: bool, odometer_m?: int, device_id?: string}>
     */
    private function mapPings(RecordVehiclePingsRequest $request): array
    {
        $pings = [];

        foreach ($request->array('pings') as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array{lat: float, lng: float, recorded_at: string, speed_kph?: float, heading?: int, accuracy_m?: float, ignition?: bool, odometer_m?: int, device_id?: string} $ping */
            $ping = [
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
                'recorded_at' => (string) $row['recorded_at'],
            ];

            if (isset($row['speed_kph'])) {
                $ping['speed_kph'] = (float) $row['speed_kph'];
            }
            if (isset($row['heading'])) {
                $ping['heading'] = (int) $row['heading'];
            }
            if (isset($row['accuracy_m'])) {
                $ping['accuracy_m'] = (float) $row['accuracy_m'];
            }
            if (isset($row['ignition'])) {
                $ping['ignition'] = (bool) $row['ignition'];
            }
            if (isset($row['odometer_m'])) {
                $ping['odometer_m'] = (int) $row['odometer_m'];
            }
            if (isset($row['device_id'])) {
                $ping['device_id'] = (string) $row['device_id'];
            }

            $pings[] = $ping;
        }

        return $pings;
    }
}
