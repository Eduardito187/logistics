<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Logistics\Shipments\Application\Ingestion\ImportShipments;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;

/**
 * Puerta de integraciones: carga masiva de pedidos desde una planilla CSV/Excel (SHP-06).
 *
 * Recibe el archivo, lo entrega al coordinador de importación (que parsea e ingresa fila por fila por
 * la API única de recepción, idempotente) y devuelve el reporte: importados, duplicados y fallidos con
 * su motivo. El coordinador es Application; Presentation solo adapta el archivo HTTP al caso de uso.
 */
final class ImportShipmentsController
{
    use JsonEnvelope;

    public function __construct(
        private readonly ImportShipments $importShipments,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        if (!$file instanceof UploadedFile) {
            return $this->error('A spreadsheet file is required under the "file" field', 422);
        }

        $contents = (string) file_get_contents($file->getRealPath());

        $report = $this->importShipments->import($contents);

        // 200: la importación se procesó entera; el reporte dice fila por fila qué pasó. Un archivo con
        // filas fallidas no es un error HTTP: es un resultado parcial legítimo que el operador revisa.
        return $this->data($report->toArray());
    }
}
