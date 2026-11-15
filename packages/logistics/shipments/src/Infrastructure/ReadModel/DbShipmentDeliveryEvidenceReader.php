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
 *    un archivo de firma: no cuenta como firma.
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
            foreach ($this->withLinkedPhoto($batch) as $shipmentId) {
                $flags[$shipmentId]['photo'] = true;
            }

            foreach ($batch as $id) {
                $key = strtolower($id);
                $result[$id] = ShipmentDeliveryEvidenceView::delivered(
                    signature: $flags[$key]['signature'] ?? false,
                    photo: $flags[$key]['photo'] ?? false,
                    document: $flags[$key]['document'] ?? false,
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
     * Envíos cuya prueba de entrega tiene al menos una foto ligada (ruta propia, lista de rutas o URL externa).
     *
     * @param  list<string> $ids
     * @return list<string>
     */
    private function withLinkedPhoto(array $ids): array
    {
        try {
            $rows = $this->connection->table('execution_deliveries')
                ->whereIn('shipment_id', $ids)
                ->get(['shipment_id', 'pod_photo_path', 'pod_photo_paths', 'pod_photo_url']);
        } catch (Throwable) {
            return [];
        }

        $withPhoto = [];
        foreach ($rows as $row) {
            $shipmentId = self::str($row->shipment_id ?? null);
            if ($shipmentId === null) {
                continue;
            }

            $hasPhoto = self::str($row->pod_photo_path ?? null) !== null
                || self::str($row->pod_photo_url ?? null) !== null
                || self::hasPaths($row->pod_photo_paths ?? null);
            if ($hasPhoto) {
                $withPhoto[] = strtolower($shipmentId);
            }
        }

        return $withPhoto;
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
