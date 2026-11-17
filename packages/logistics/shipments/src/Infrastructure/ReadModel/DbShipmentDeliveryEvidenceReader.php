<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Application\Contract\ShipmentDeliveryEvidenceReader;
use Logistics\Shipments\Application\ReadModel\ShipmentDeliveryEvidenceView;
use Throwable;

/**
 * Implementación SQL de la evidencia de entrega de los envíos (enriquecimiento de lectura de la app).
 *
 * Lectura cruzada de SOLO LECTURA (por el query builder, sin tocar el dominio de ejecución) con el MISMO
 * criterio de la galería de evidencia de la entrega (GET /app/deliveries/{id}/evidence):
 *  - execution_delivery_evidence.kind → `signature` = firma, `pod` = foto de la entrega, `id_document` =
 *    documento del cliente. La evidencia de daños (`damage`) no es evidencia de la entrega: no cuenta;
 *  - execution_deliveries → las fotos ligadas a la prueba de entrega (`pod_photo_path`, `pod_photo_paths`,
 *    `pod_photo_url`) cuentan como foto. `pod_confirmation` es el marcador de confirmación (QR o texto), no
 *    un archivo de firma: no cuenta como firma. De la MISMA fila sale el cierre con faltante
 *    (`closed_with_shortage`) y cuántos productos faltaron (tamaño de `store_drop_missing_units`), para el
 *    tag «Entregado con faltante (N)». La lista de productos faltantes NO se expone: se ve por el reporte de
 *    discrepancias.
 *
 * Costo fijo por página: DOS consultas por lote de ids (whereIn), nunca una por envío. Cada fuente va
 * aislada en su try/catch: si una tabla no está disponible, sus indicadores quedan en false.
 */
final readonly class DbShipmentDeliveryEvidenceReader implements ShipmentDeliveryEvidenceReader
{
    /** Tope de ids por whereIn (acota el tamaño de la sentencia cuando no se pagina). */
    private const int BATCH = 1000;

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Tipo de evidencia capturada → indicador que enciende. */
    private const array KIND_TO_FLAG = [
        'signature' => 'signature',
        'pod' => 'photo',
        'id_document' => 'document',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forDeliveredShipmentIds(array $shipmentIds): array
    {
        // Solo ids con forma de UUID: nunca se compara otra cosa contra una columna uuid (22P02).
        $ids = [];
        foreach ($shipmentIds as $id) {
            if (preg_match(self::UUID_PATTERN, $id) === 1) {
                $ids[$id] = true;
            }
        }
        $ids = array_map(strval(...), array_keys($ids));

        $result = [];
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $flags = $this->captured($batch);
            $closure = $this->deliveryClosure($batch);

            foreach ($batch as $id) {
                $key = strtolower($id);
                $result[$id] = ShipmentDeliveryEvidenceView::delivered(
                    signature: $flags[$key]['signature'] ?? false,
                    // Foto: evidencia de tipo `pod` (captured) O foto ligada a la prueba de entrega (closure).
                    photo: ($flags[$key]['photo'] ?? false) || ($closure[$key]['photo'] ?? false),
                    document: $flags[$key]['document'] ?? false,
                    withShortage: $closure[$key]['with_shortage'] ?? false,
                    missingCount: $closure[$key]['missing_count'] ?? null,
                );
            }
        }

        return $result;
    }

    /**
     * Evidencia capturada (firma, foto, documento) por envío: una consulta agregada por (envío, tipo).
     *
     * @param  list<string>                       $ids
     * @return array<string, array<string, bool>>
     */
    private function captured(array $ids): array
    {
        try {
            $rows = $this->connection->table('execution_delivery_evidence')
                ->whereIn('shipment_id', $ids)
                ->whereIn('kind', array_keys(self::KIND_TO_FLAG))
                ->groupBy('shipment_id', 'kind')
                ->get(['shipment_id', 'kind']);
        } catch (Throwable) {
            return [];
        }

        $flags = [];
        foreach ($rows as $row) {
            $shipmentId = self::str($row->shipment_id ?? null);
            $flag = self::KIND_TO_FLAG[strtolower((string) self::str($row->kind ?? null))] ?? null;
            if ($shipmentId !== null && $flag !== null) {
                $flags[strtolower($shipmentId)][$flag] = true;
            }
        }

        return $flags;
    }

    /**
     * Cierre de la entrega por envío, desde la fila de `execution_deliveries`: si tiene una foto ligada a la
     * prueba de entrega, si se cerró con faltante y cuántos productos faltaron. Una sola consulta por lote.
     *
     * `with_shortage` sale de `closed_with_shortage` (lo encienden el cierre de un traspaso conciliado con
     * diferencias y el cierre manual de oficina; en una entrega normal queda en false). `missing_count` es el
     * tamaño de `store_drop_missing_units` (productos que el chofer declaró como faltantes); null si no hubo
     * faltante o no se conoce la cantidad.
     *
     * @param  list<string>                                                                      $ids
     * @return array<string, array{photo: bool, with_shortage: bool, missing_count: int | null}>
     */
    private function deliveryClosure(array $ids): array
    {
        try {
            $rows = $this->connection->table('execution_deliveries')
                ->whereIn('shipment_id', $ids)
                ->get(['shipment_id', 'pod_photo_path', 'pod_photo_paths', 'pod_photo_url', 'closed_with_shortage', 'store_drop_missing_units']);
        } catch (Throwable) {
            return [];
        }

        $closure = [];
        foreach ($rows as $row) {
            $shipmentId = self::str($row->shipment_id ?? null);
            if ($shipmentId === null) {
                continue;
            }

            $hasPhoto = self::str($row->pod_photo_path ?? null) !== null
                || self::str($row->pod_photo_url ?? null) !== null
                || self::hasPaths($row->pod_photo_paths ?? null);
            $withShortage = self::truthy($row->closed_with_shortage ?? null);
            $missing = self::countPaths($row->store_drop_missing_units ?? null);

            $closure[strtolower($shipmentId)] = [
                'photo' => $hasPhoto,
                'with_shortage' => $withShortage,
                // Sin faltantes declarados no se informa cantidad (null), aunque el cierre venga con faltante.
                'missing_count' => $missing > 0 ? $missing : null,
            ];
        }

        return $closure;
    }

    /** ¿La columna jsonb de rutas de fotos trae al menos una ruta no vacía? */
    private static function hasPaths(mixed $raw): bool
    {
        $paths = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($paths)) {
            return false;
        }

        foreach ($paths as $path) {
            if (is_string($path) && trim($path) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Cuántas entradas válidas trae la columna jsonb de faltantes (`[{unit_id, reason}]`). */
    private static function countPaths(mixed $raw): int
    {
        $items = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($items)) {
            return 0;
        }

        $count = 0;
        foreach ($items as $item) {
            $unitId = is_array($item) ? ($item['unit_id'] ?? null) : null;
            if (is_string($unitId) && trim($unitId) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /** Interpreta el booleano de la base (bool, entero o texto de PostgreSQL). */
    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /** Normaliza a string no vacío o null. */
    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
