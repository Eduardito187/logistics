<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Ingest;

use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Application\Contract\ShipmentCustodyReader;
use Logistics\Telemetry\Domain\ValueObject\PingSource;

/**
 * Puente de la posición POR ENVÍO hacia la ingesta de telemetría.
 *
 * El endpoint por envío (`POST /app/tracking/shipments/{id}/position`) solo actualiza la miga de pan del
 * envío (contexto tracking): NO alimenta trips, posición en vivo, desvío de ruta ni reruteo, que viven en la
 * ingesta de telemetría. Este puente resuelve el vehículo que custodia el envío y mete esa lectura por el
 * MISMO camino que `POST /app/telemetry/pings`, para que el motor de desvío/reruteo funcione automáticamente
 * aunque la app mande el GPS por envío. No requiere cambiar la app ni configurar nada por `.env`.
 *
 * No crea bucle con el fan-out telemetría → seguimiento: aquel despacha UpdateTruckPosition directo en el bus
 * (no pasa por el controller), así que nunca reingresa por este puente, que solo invoca el controller.
 *
 * Idempotente: todas las lecturas del mismo vehículo e instante comparten un `fix_id` sintético, de modo que
 * varios envíos a bordo reportando la misma posición colapsan en UN ping (no inflan trips ni distancia).
 * Tolerante: un envío sin entrega/sin vehículo, o que ya bajó del camión, no ingresa telemetría.
 */
final readonly class ShipmentPositionBridge
{
    /** Estados de entrega con el envío efectivamente a bordo del vehículo. */
    private const array ONBOARD = ['LOADED', 'IN_TRANSIT'];

    public function __construct(
        private ShipmentCustodyReader $custody,
        private IngestVehiclePings $ingest,
    ) {}

    /**
     * Mete la posición del envío por la ingesta de telemetría del vehículo que lo custodia. No hace nada si
     * el puente está apagado, si el envío no está a bordo de un vehículo, o si no se resuelve el vehículo.
     */
    public function bridge(
        string $shipmentId,
        float $latitude,
        float $longitude,
        string $reportedAt,
        ?float $speedMps = null,
        ?float $headingDeg = null,
        ?float $accuracyM = null,
    ): void {
        if (!(bool) config('logistics-telemetry.ingest.bridge_shipment_position', true)) {
            return;
        }

        $custody = $this->custody->custodyOf($shipmentId);
        if ($custody === null || !in_array($custody['status'], self::ONBOARD, true)) {
            return;
        }

        $vehicleId = $custody['vehicle_id'];
        if ($vehicleId === '') {
            return;
        }

        $this->ingest->ingest(new RecordVehiclePings(
            vehicleId: $vehicleId,
            driverId: null,
            routeId: null,
            source: PingSource::App->value,
            pings: [$this->ping($vehicleId, $latitude, $longitude, $reportedAt, $speedMps, $headingDeg, $accuracyM)],
        ));
    }

    /**
     * Arma la lectura primitiva para la ingesta. El `fix_id` sintético (vehículo + instante) da idempotencia
     * entre envíos a bordo que reportan la misma posición Y contra el mismo fix que la app mande por
     * `/app/telemetry/pings` (mismo esquema `{vehicle_id}:{recorded_at}`): si llegan ambos, colapsan en UN
     * ping. La velocidad se convierte a km/h (la ingesta también acepta m/s, pero acá ya la normalizamos).
     *
     * @return array{lat: float, lng: float, recorded_at: string, fix_id: string, speed_kph?: float, heading?: int, accuracy_m?: float}
     */
    private function ping(
        string $vehicleId,
        float $latitude,
        float $longitude,
        string $reportedAt,
        ?float $speedMps,
        ?float $headingDeg,
        ?float $accuracyM,
    ): array {
        $ping = [
            'lat' => $latitude,
            'lng' => $longitude,
            'recorded_at' => $reportedAt,
            'fix_id' => $vehicleId.':'.$reportedAt,
        ];

        if ($speedMps !== null) {
            $ping['speed_kph'] = $speedMps * 3.6;
        }
        if ($headingDeg !== null) {
            $ping['heading'] = (int) round($headingDeg);
        }
        if ($accuracyM !== null) {
            $ping['accuracy_m'] = $accuracyM;
        }

        return $ping;
    }
}
