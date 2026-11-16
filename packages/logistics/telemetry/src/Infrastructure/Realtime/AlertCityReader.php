<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Realtime;

use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\AlertRecord;

/**
 * Resuelve la "ciudad" (departamento) de una alerta para la señal `alerts.updated`: de la alerta saca su
 * vehículo y del vehículo su nodo base ({@see FleetDirectory::homeBases}). Así el aviso también alcanza al
 * planificador de esa ciudad (`operations.city.<departamento>`), no solo a la oficina (`operations.all`).
 *
 * Corre en la cola, nunca en el request. Tolerante: una alerta sin vehículo conocido o un vehículo sin nodo
 * base devuelven null y la señal sale solo por `operations.all`.
 */
final readonly class AlertCityReader
{
    public function __construct(
        private FleetDirectory $fleet,
    ) {}

    /**
     * Departamento (código tipo `santa_cruz`) del camión de la alerta, o null si no se puede resolver.
     */
    public function departmentOf(string $alertId): ?string
    {
        $vehicleId = AlertRecord::query()->whereKey($alertId)->value('vehicle_id');
        if (!is_string($vehicleId) || $vehicleId === '') {
            return null;
        }

        $department = $this->fleet->homeBases([$vehicleId])[$vehicleId]['department'] ?? null;

        return is_string($department) && $department !== '' ? $department : null;
    }
}
