<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\DailyKpisView;

/**
 * Lado de LECTURA de los KPIs operativos del día (CQRS). Solo agregados SQL de solo lectura sobre las
 * tablas de ejecución/flota (sin acoplar sus dominios); la implementación puede cachear unos segundos.
 */
interface DailyKpisReader
{
    /**
     * KPIs del día `$date` (Y-m-d). Filtros opcionales combinados con AND: nodo destino del envío,
     * vehículo de la ruta y chofer (fleet driver id) de la ruta. `$scope` solo etiqueta el resultado.
     */
    public function daily(
        string $date,
        string $scope,
        ?string $nodeId = null,
        ?string $vehicleId = null,
        ?string $driverId = null,
    ): DailyKpisView;
}
