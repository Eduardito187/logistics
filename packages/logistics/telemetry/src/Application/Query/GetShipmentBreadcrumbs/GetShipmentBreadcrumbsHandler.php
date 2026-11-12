<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetShipmentBreadcrumbs;

use DateInterval;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Application\Contract\ShipmentCustodyReader;
use Logistics\Telemetry\Application\ReadModel\ShipmentBreadcrumbsView;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\Service\TrackDownsampler;

/**
 * Migas de pan de un envío: traza del vehículo entre la carga del envío y su entrega/fallo (o ahora).
 *
 * Ventana de la custodia (execution) + pings del vehículo en esa ventana (rango por `recorded_at`, servido por
 * el índice (vehicle_id, recorded_at) y la poda de particiones) + reducción pura (TrackDownsampler). La
 * ventana se acota a `breadcrumbs.max_window_hours` hacia atrás desde su fin para no barrer días enteros si un
 * envío quedó cargado sin cerrar. Sin carga registrada → sin puntos (ventana null).
 *
 * @implements QueryHandler<GetShipmentBreadcrumbs, ShipmentBreadcrumbsView|null>
 */
final readonly class GetShipmentBreadcrumbsHandler implements QueryHandler
{
    public function __construct(
        private ShipmentCustodyReader $custody,
        private PingRepository $pings,
        private FleetDirectory $fleet,
        private Clock $clock,
        private TrackDownsampler $downsampler = new TrackDownsampler,
    ) {}

    public function handle(Query $query): ?ShipmentBreadcrumbsView
    {
        assert($query instanceof GetShipmentBreadcrumbs);

        $custody = $this->custody->custodyOf($query->shipmentId);
        if ($custody === null) {
            return null;
        }

        $plate = $this->fleet->plates([$custody['vehicle_id']])[$custody['vehicle_id']] ?? null;
        $from = $custody['loaded_at'];

        if ($from === null) {
            return new ShipmentBreadcrumbsView(
                $query->shipmentId,
                $custody['vehicle_id'],
                $plate,
                $custody['status'],
                null,
                null,
                false,
                0,
                [],
            );
        }

        $open = $custody['closed_at'] === null;
        $to = $custody['closed_at'] ?? $this->clock->now();

        $maxHours = max(1, (int) config('logistics-telemetry.breadcrumbs.max_window_hours', 72));
        $floor = $to->sub(new DateInterval('PT'.$maxHours.'H'));
        $effectiveFrom = $from < $floor ? $floor : $from;

        $track = $effectiveFrom <= $to ? $this->pings->track($custody['vehicle_id'], $effectiveFrom, $to) : [];
        $reduced = $this->downsampler->downsample(
            $track,
            (float) config('logistics-telemetry.breadcrumbs.min_spacing_m', 25.0),
            (int) config('logistics-telemetry.breadcrumbs.max_points', 500),
        );

        return new ShipmentBreadcrumbsView(
            $query->shipmentId,
            $custody['vehicle_id'],
            $plate,
            $custody['status'],
            $from->format(DATE_ATOM),
            $to->format(DATE_ATOM),
            $open,
            count($track),
            array_map(static fn (VehiclePing $ping): array => [
                'lat' => $ping->point()->lat,
                'lng' => $ping->point()->lng,
                'speed_kph' => $ping->speed()?->kph,
                'heading' => $ping->heading()?->degrees,
                'recorded_at' => $ping->recordedAt()->format(DATE_ATOM),
            ], $reduced),
        );
    }
}
