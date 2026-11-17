<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\ShipmentItineraryReader;
use Logistics\Execution\Application\ReadModel\ItineraryLeg;
use Throwable;

/**
 * Lee el itinerario multi-tramo de un envío directamente de la tabla `shipment_legs` (query builder, solo
 * lectura): la ejecución no se acopla al dominio ni al ReadModel de shipments, y cada contexto queda
 * extraíble a su propio servicio (ADR 001/008).
 *
 * Tolerante por diseño (offline-first, datos ausentes): un id que no es UUID, un envío sin tramos o un fallo
 * de la consulta se comportan como «sin itinerario» (destino final null, no es intermedio, sin tramo
 * abierto). Así los envíos de UN solo tramo (sin filas en `shipment_legs`) conservan el comportamiento
 * histórico: llegar a su destino cierra la entrega como siempre.
 */
final readonly class DbShipmentItineraryReader implements ShipmentItineraryReader
{
    /** Validación de forma: comparar un valor no-UUID contra una columna uuid lanza 22P02 en Postgres. */
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Estados de un tramo todavía abierto (ver LegStatus en shipments). */
    private const array OPEN_STATUSES = ['pending', 'in_transit'];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function finalDestinationNodeId(string $shipmentId): ?string
    {
        $legs = $this->legs($shipmentId);
        if ($legs === []) {
            return null;
        }

        // El último tramo marca el destino final; su to_node_id null = entrega a domicilio (sin nodo).
        $last = $legs[array_key_last($legs)];
        $node = trim((string) ($last->to_node_id ?? ''));

        return $node !== '' ? $node : null;
    }

    public function isIntermediateHub(string $shipmentId, string $nodeId): bool
    {
        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            return false;
        }

        $legs = $this->legs($shipmentId);
        // Con un solo tramo (o ninguno) no hay hubs intermedios: el único destino es el final.
        if (count($legs) < 2) {
            return false;
        }

        $lastSeq = (int) $legs[array_key_last($legs)]->seq;
        foreach ($legs as $leg) {
            $to = trim((string) ($leg->to_node_id ?? ''));
            // Es intermedio si es el to_node_id de un tramo que NO es el último.
            if ($to !== '' && (int) $leg->seq < $lastSeq && strtolower($to) === strtolower($nodeId)) {
                return true;
            }
        }

        return false;
    }

    public function nextOpenLeg(string $shipmentId): ?ItineraryLeg
    {
        foreach ($this->legs($shipmentId) as $leg) {
            if (in_array(strtolower((string) $leg->status), self::OPEN_STATUSES, true)) {
                return new ItineraryLeg(
                    (int) $leg->seq,
                    $this->nullableString($leg->from_node_id),
                    $this->nullableString($leg->to_node_id),
                    (string) $leg->haulage_mode,
                    $this->nullableString($leg->carrier_id),
                    (string) $leg->status,
                );
            }
        }

        return null;
    }

    /**
     * Tramos del envío ordenados por `seq` (recorrido). Lista vacía si el id no es UUID, si el envío no tiene
     * itinerario o si la consulta falla.
     *
     * @return list<\stdClass>
     */
    private function legs(string $shipmentId): array
    {
        if (preg_match(self::UUID_PATTERN, trim($shipmentId)) !== 1) {
            return [];
        }

        try {
            return array_values($this->connection->table('shipment_legs')
                ->where('shipment_id', trim($shipmentId))
                ->orderBy('seq')
                ->get(['seq', 'from_node_id', 'to_node_id', 'haulage_mode', 'carrier_id', 'status'])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }
}
