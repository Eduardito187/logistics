<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\Ingestion\ParsedShipmentRow;

/**
 * Lee una planilla de carga de pedidos (CSV/Excel) y la traduce a filas parseadas: cada una, o un
 * comando de ingreso listo, o un error con su número de fila.
 *
 * El QUÉ (convertir un archivo a filas normalizadas) lo define Application; el CÓMO (formato CSV, XLSX,
 * la librería concreta) es decisión de Infrastructure. Así el caso de uso de importación no se acopla
 * a ningún formato ni librería de planillas.
 */
interface ShipmentSpreadsheetParser
{
    /**
     * @param  string                  $contents contenido crudo del archivo subido
     * @return list<ParsedShipmentRow>
     */
    public function parse(string $contents): array;
}
