<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Pages;

use BackedEnum;
use DateTimeImmutable;
use Filament\Pages\Page;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\Query\GetVehicleTrack\GetVehicleTrack;
use Logistics\Telemetry\Application\ReadModel\PingView;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;

/**
 * Mapa en vivo de toda la flota sobre OpenStreetMap (Leaflet, sin API key). Pinta un marcador por
 * vehículo con su última posición conocida; al hacer clic en un camión se dibuja su recorrido del día.
 *
 * Nota de arquitectura: TODOS los datos salen del lado de lectura CQRS (QueryBus → GetLiveFleetPositions
 * / GetVehicleTrack → ReadModels), nunca de un acceso directo a Eloquent ni al dominio. El refresco es
 * por polling desde el navegador, que vuelve a llamar al método Livewire `positions()`; no hay I/O
 * externo síncrono en el request. Texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class LiveFleetMap extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 10;

    protected string $view = 'logistics-telemetry::filament.pages.live-fleet-map';

    public static function getNavigationGroup(): string
    {
        return __('Telemetry');
    }

    public static function getNavigationLabel(): string
    {
        return __('Live fleet map');
    }

    public function getTitle(): string
    {
        return __('Live fleet map');
    }

    /**
     * Posición en vivo de cada vehículo como primitivos, lista para el mapa. Se expone como método
     * público para que el polling del navegador (`$wire.positions()`) la vuelva a pedir sin recargar.
     *
     * @return list<array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string}>
     */
    public function positions(): array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var list<VehicleLiveView> $views */
        $views = $queryBus->ask(new GetLiveFleetPositions);

        return array_map(static fn (VehicleLiveView $view): array => $view->toArray(), $views);
    }

    /**
     * Recorrido (track) del vehículo durante el día de hoy, para dibujarlo al hacer clic en su marcador.
     * Se pide al lado de lectura entre el inicio y el fin del día en curso.
     *
     * @return list<array{lat: float, lng: float, speed_kph: float|null, heading: int|null, recorded_at: string}>
     */
    public function vehicleTrack(string $vehicleId): array
    {
        $from = (new DateTimeImmutable('today'))->format(DATE_ATOM);
        $to = (new DateTimeImmutable('tomorrow'))->format(DATE_ATOM);

        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var list<PingView> $pings */
        $pings = $queryBus->ask(new GetVehicleTrack($vehicleId, $from, $to));

        return array_map(static fn (PingView $ping): array => $ping->toArray(), $pings);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            // Posiciones iniciales para el primer pintado; el polling las refresca luego.
            'positions' => $this->positions(),
        ];
    }
}
