<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Ingestion;

use Logistics\Shipments\Application\Contract\ShipmentSpreadsheetParser;
use Logistics\Shipments\Application\Ingestion\ParsedShipmentRow;

/**
 * Parser de planillas que detecta el formato por el contenido y delega al parser adecuado.
 *
 * Hoy soporta CSV real (CsvShipmentSpreadsheetParser, sin dependencias). Reconoce además los binarios
 * de Excel (XLSX = ZIP con "PK..", XLS = compuesto OLE) para devolver un error claro y accionable en vez
 * de intentar leerlos como texto y escupir basura:
 *
 *   El soporte nativo de XLSX/XLS requiere la dependencia phpoffice/phpspreadsheet (todavía no instalada
 *   en el proyecto). Mientras tanto, el operador exporta la planilla a CSV UTF-8 y la vuelve a subir.
 *
 * Cuando se incorpore phpoffice/phpspreadsheet, se agrega aquí un XlsxShipmentSpreadsheetParser y este
 * delegador lo enruta sin tocar el caso de uso de importación ni el resto del contexto.
 */
final class DelegatingShipmentSpreadsheetParser implements ShipmentSpreadsheetParser
{
    /** Firma de archivos ZIP (y por tanto XLSX/ODS, que son ZIP por dentro). */
    private const ZIP_SIGNATURE = "PK\x03\x04";

    /** Firma del formato compuesto OLE2 (XLS clásico de Excel 97-2003). */
    private const OLE2_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private const UNSUPPORTED_XLSX = 'formato Excel (XLSX) no soportado todavía: exportá la planilla a CSV UTF-8 y volvé a subirla '
        .'(el soporte nativo de XLSX requiere la dependencia phpoffice/phpspreadsheet, aún no instalada)';

    private const UNSUPPORTED_XLS = 'formato Excel binario (XLS) no soportado: guardá la planilla como CSV UTF-8 y volvé a subirla';

    public function __construct(
        private readonly CsvShipmentSpreadsheetParser $csv,
    ) {}

    public function parse(string $contents): array
    {
        if (str_starts_with($contents, self::ZIP_SIGNATURE)) {
            return [ParsedShipmentRow::invalid(0, self::UNSUPPORTED_XLSX)];
        }

        if (str_starts_with($contents, self::OLE2_SIGNATURE)) {
            return [ParsedShipmentRow::invalid(0, self::UNSUPPORTED_XLS)];
        }

        return $this->csv->parse($contents);
    }
}
