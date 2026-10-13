<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources;

use App\Filament\Pages\CustomerProfile;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\RouteStopRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource\Pages\ListVehicleRoutes;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource\Pages\ViewVehicleRoute;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;

/**
 * Admin de la ruta del chofer: vista LEGIBLE de la hoja de ruta por camión (paradas en orden y estado
 * de cada una).
 *
 * Es un agregado transaccional, no una fila editable: la ruta se arma por el comando AssembleDailyRoute
 * y avanza sola al registrar las entregas/fallos (comandos de ejecución). Por eso este resource es de
 * SOLO LECTURA (sin crear/editar/borrar): sirve al supervisor para ver dónde va cada camión.
 *
 * Datos legibles: el camión por su placa (vehicle.plate) y cada parada por su referencia de pedido,
 * cliente y dirección (foto guardada en la parada), nunca por UUID. Todo el texto visible en inglés.
 *
 * @extends resource<VehicleRouteRecord>
 */
final class VehicleRouteResource extends Resource
{
    protected static ?string $model = VehicleRouteRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string
    {
        return __('Execution');
    }

    public static function getNavigationLabel(): string
    {
        return __('Driver Routes');
    }

    public static function getModelLabel(): string
    {
        return __('driver route');
    }

    public static function getPluralModelLabel(): string
    {
        return __('driver routes');
    }

    /**
     * Título humano del registro: día de la ruta y placa del camión, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof VehicleRouteRecord) {
            return null;
        }

        $plate = $record->vehicle->plate ?? $record->vehicle_id;

        return "{$record->route_date} · {$plate}";
    }

    /**
     * Eager-load del camión y de las paradas para evitar N+1 al pintar placas y progreso.
     *
     * @return Builder<VehicleRouteRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'stops'])->withCount('stops');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('route_date')
                    ->label(__('Route date'))
                    ->date()
                    ->sortable(),
                // Placa del camión vía relación con Fleet (no el UUID vehicle_id).
                TextColumn::make('vehicle.plate')
                    ->label(__('Vehicle'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                    ->color(static fn (string $state): string => self::statusColor($state)),
                TextColumn::make('stops_count')
                    ->label(__('Stops'))
                    ->alignCenter(),
                // Progreso: paradas atendidas / total, derivado de las paradas ya cargadas.
                TextColumn::make('progress')
                    ->label(__('Progress'))
                    ->alignCenter()
                    ->state(static fn (VehicleRouteRecord $record): string => self::progress($record)),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
            ])
            ->defaultSort('route_date', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            // Una sola columna lógica a nivel de página: cada bloque ocupa todo el ancho para que no
            // queden huecos (antes la ficha de ruta quedaba a la izquierda y el mapa a la derecha).
            ->columns(1)
            ->components([
                // Fila compacta superior: datos de la ruta en línea (fecha / vehículo / estado).
                Section::make(__('Route'))
                    ->schema([
                        TextEntry::make('route_date')->label(__('Route date'))->date(),
                        TextEntry::make('vehicle.plate')->label(__('Vehicle'))->placeholder('—'),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                            ->color(static fn (string $state): string => self::statusColor($state)),
                    ])
                    // 1 columna en móvil, 3 en pantallas medianas hacia arriba: datos en una sola línea.
                    ->columns(['default' => 1, 'sm' => 3])
                    ->columnSpanFull(),
                // Mapa de la ruta sobre OpenStreetMap: paradas numeradas, trazado planificado y recorrido
                // real del camión (GPS de los escaneos), para comparar "por dónde fue" vs "por dónde debía ir".
                Section::make(__('Route map'))
                    ->schema([
                        View::make('logistics-execution::filament.route-map')
                            ->viewData(static fn (VehicleRouteRecord $record): array => self::mapData($record))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                // Paradas en tarjetas responsivas: 1 por fila en móvil, 2 en tablet y 3 en escritorio,
                // de modo que llenen el ancho disponible sin dejar espacios en blanco.
                Section::make(__('Stops in delivery order'))
                    ->schema([
                        RepeatableEntry::make('stops')
                            ->hiddenLabel()
                            ->grid(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->schema([
                                TextEntry::make('sequence')
                                    ->label(__('#'))
                                    ->badge()
                                    ->color('primary'),
                                TextEntry::make('status')
                                    ->label(__('Stop status'))
                                    ->badge()
                                    ->formatStateUsing(static fn (string $state): string => self::stopStatusLabel($state))
                                    ->color(static fn (string $state): string => self::stopStatusColor($state)),
                                // Pedido: enlace a la vista completa del pedido en una pestaña nueva.
                                TextEntry::make('order_reference')
                                    ->label(__('Order'))
                                    ->placeholder(static fn (?RouteStopRecord $record): string => $record?->shipment->external_id ?? '—')
                                    ->color('primary')
                                    ->url(
                                        static fn (?RouteStopRecord $record): ?string => self::shipmentViewUrl($record),
                                        shouldOpenInNewTab: true,
                                    )
                                    ->columnSpanFull(),
                                // Cliente: enlace al PERFIL COMPLETO del cliente (datos + pedidos + direcciones),
                                // no al listado filtrado, en una pestaña nueva.
                                TextEntry::make('customer_name')
                                    ->label(__('Customer'))
                                    ->placeholder(static fn (?RouteStopRecord $record): string => $record?->shipment->customer_name ?? '—')
                                    ->color('primary')
                                    ->url(
                                        static fn (?RouteStopRecord $record): ?string => self::customerProfileUrl($record),
                                        shouldOpenInNewTab: true,
                                    )
                                    ->columnSpanFull(),
                                // Dirección: texto plano (no es link). La ubicación se consulta con la acción
                                // "Ver en el mapa" de abajo, que centra el punto de esta parada en el mapa.
                                TextEntry::make('address')
                                    ->label(__('Address'))
                                    ->placeholder(static fn (?RouteStopRecord $record): string => $record?->shipment->address_text ?? '—')
                                    ->columnSpanFull(),
                                TextEntry::make('eta')
                                    ->label(__('ETA'))
                                    ->dateTime()
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                                // Hace clickeable TODO el recuadro de la parada: un clic en cualquier parte de la
                                // tarjeta centra y abre su punto en el mapa de la ruta de arriba (los links de
                                // texto Pedido/Cliente se respetan y siguen navegando). Es solo cableado, va oculto.
                                View::make('logistics-execution::filament.route-stop-clickable')
                                    ->viewData(static fn (RouteStopRecord $record): array => [
                                        'seq' => $record->sequence,
                                        // Solo las paradas con coordenadas tienen marcador en el mapa; las que no
                                        // están geocodificadas no se hacen clickeables (no hay punto que mostrar).
                                        'hasPoint' => self::coord($record->lat ?? $record->shipment?->lat) !== null
                                            && self::coord($record->lng ?? $record->shipment?->lng) !== null,
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * URL a la vista completa del pedido de una parada (pestaña nueva). Null si la parada no tiene pedido.
     */
    private static function shipmentViewUrl(?RouteStopRecord $record): ?string
    {
        if ($record?->shipment_id === null || $record->shipment_id === '') {
            return null;
        }

        return ShipmentResource::getUrl('view', ['record' => $record->shipment_id]);
    }

    /**
     * URL al perfil completo del cliente de la parada (datos + pedidos + direcciones), en pestaña nueva.
     * Null si la parada no tiene cliente. El perfil lo provee el frente Shipments; aquí solo se enlaza.
     */
    private static function customerProfileUrl(?RouteStopRecord $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $customer = $record->customer_name ?? $record->shipment?->customer_name;

        if ($customer === null || $customer === '') {
            return null;
        }

        return CustomerProfile::getUrl(['customer' => $customer]);
    }

    /**
     * Datos del mapa de la ruta (coordenadas ya resueltas) para la vista Blade con Leaflet + OSM.
     *
     * - stops: paradas con coordenadas, en orden de reparto, para los marcadores numerados.
     * - plannedGeometry: polilínea del trazado planificado SIGUIENDO CALLES, ya calculada y persistida por
     *   el frente de Planning (planning_plans.route_geometry). Si está presente, el mapa la dibuja directo
     *   sin consultar OSRM desde el navegador. Puede venir vacía (plan sin geometría aún).
     * - planned: línea recta que une las paradas en secuencia; respaldo cuando no hay geometría persistida
     *   ni OSRM del navegador.
     * - actual: polilínea del recorrido real del camión a partir de los puntos GPS de TRÁNSITO
     *   (scan_events de tipo IN_TRANSIT) de las entregas de esta ruta, ordenados por occurred_at. El mapa
     *   la pega a calles con OSRM /match en el navegador (map matching); si falla, dibuja la traza cruda.
     *
     * @return array{mapId: string, stops: list<array{seq: int, lat: float, lng: float, order: string, customer: string, status: string}>, plannedGeometry: list<array{0: float, 1: float}>, planned: list<array{0: float, 1: float}>, actual: list<array{0: float, 1: float}>}
     */
    private static function mapData(VehicleRouteRecord $record): array
    {
        /** @var Collection<int, RouteStopRecord> $stops */
        $stops = $record->stops()->with('shipment')->get();

        $points = [];
        $planned = [];

        foreach ($stops as $stop) {
            $lat = self::coord($stop->lat ?? $stop->shipment?->lat);
            $lng = self::coord($stop->lng ?? $stop->shipment?->lng);

            if ($lat === null || $lng === null) {
                // Parada sin geocodificar: se omite del mapa (no se puede dibujar).
                continue;
            }

            $points[] = [
                'seq' => (int) $stop->sequence,
                'lat' => $lat,
                'lng' => $lng,
                'order' => $stop->order_reference ?? $stop->shipment->external_id ?? '—',
                'customer' => $stop->customer_name ?? $stop->shipment->customer_name ?? '—',
                'status' => self::stopStatusLabel((string) $stop->status),
            ];
            $planned[] = [$lat, $lng];
        }

        return [
            'mapId' => 'route-map-'.$record->getKey(),
            'stops' => $points,
            'plannedGeometry' => self::plannedGeometry($record),
            'planned' => $planned,
            'actual' => self::actualTrack($record, array_values(array_filter(
                $stops->map(static fn (RouteStopRecord $stop): string => $stop->shipment_id)->all(),
            ))),
        ];
    }

    /**
     * Línea planificada que sigue las calles, ya calculada y PERSISTIDA por el frente de Planning en
     * planning_plans.route_geometry (jsonb). Acá solo se LEE (lectura cross-context de solo lectura, igual
     * que la placa del camión vía Fleet): Ejecución nunca escribe sobre Planning. Así el mapa no depende de
     * que el navegador consulte OSRM en cada apertura.
     *
     * La ruta de Ejecución y el plan comparten clave natural (vehículo + día): se empareja
     * execution_driver_routes(vehicle_id, route_date) con planning_plans(vehicle_id, plan_date).
     *
     * Degrada con gracia: si la tabla o la columna todavía no existen (migración de Planning aún no aplicada
     * o plan viejo), o la geometría no es usable, devuelve [] y el mapa cae al OSRM del navegador y, en
     * última instancia, a la línea recta entre paradas.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function plannedGeometry(VehicleRouteRecord $record): array
    {
        if (!DbSchema::hasTable('planning_plans') || !DbSchema::hasColumn('planning_plans', 'route_geometry')) {
            return [];
        }

        /** @var mixed $raw */
        $raw = DB::table('planning_plans')
            ->where('vehicle_id', $record->vehicle_id)
            ->where('plan_date', $record->route_date)
            ->value('route_geometry');

        return self::normalizeGeometry($raw);
    }

    /**
     * Normaliza la geometría persistida a una lista de pares [lat, lng] lista para Leaflet.
     *
     * Acepta las formas que puede persistir Planning: un objeto GeoJSON LineString
     * ({"type":"LineString","coordinates":[[lng,lat],...]}) o directamente la lista de coordenadas
     * [[lng,lat],...] (orden GeoJSON: longitud primero, como la devuelve OSRM). Ante cualquier forma
     * inesperada devuelve [] para que el mapa degrade sin romperse. Exige al menos dos puntos para ser
     * una línea dibujable.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function normalizeGeometry(mixed $raw): array
    {
        if (is_string($raw)) {
            /** @var mixed $raw */
            $raw = json_decode($raw, true);
        }

        if (is_array($raw) && isset($raw['coordinates']) && is_array($raw['coordinates'])) {
            $raw = $raw['coordinates'];
        }

        if (!is_array($raw)) {
            return [];
        }

        $line = [];
        foreach ($raw as $pair) {
            if (!is_array($pair) || !array_key_exists(0, $pair) || !array_key_exists(1, $pair)) {
                return [];
            }

            $lng = self::coord($pair[0]);
            $lat = self::coord($pair[1]);

            if ($lat === null || $lng === null) {
                return [];
            }

            // Persistido como [lng, lat] (GeoJSON); Leaflet dibuja con [lat, lng].
            $line[] = [$lat, $lng];
        }

        return count($line) >= 2 ? $line : [];
    }

    /**
     * Recorrido real del camión: SOLO los puntos GPS de TRÁNSITO (escaneos IN_TRANSIT) de las entregas de
     * esta ruta (mismo camión y pedidos de las paradas), ordenados por momento de captura. Se excluyen a
     * propósito los escaneos de preparación, carga, descarga y prueba de entrega: esos ocurren parados en
     * el CD o en el punto del cliente y, mezclados, dibujaban una línea roja sin sentido. Si no hay puntos
     * de tránsito, se devuelve vacío y el mapa no traza el recorrido real. Omite escaneos sin coordenadas.
     *
     * @param  list<string>                    $shipmentIds
     * @return list<array{0: float, 1: float}>
     */
    private static function actualTrack(VehicleRouteRecord $record, array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $deliveryIds = DeliveryRecord::query()
            ->where('vehicle_id', $record->vehicle_id)
            ->whereIn('shipment_id', $shipmentIds)
            ->pluck('id')
            ->all();

        if ($deliveryIds === []) {
            return [];
        }

        $track = [];
        $events = ScanEventRecord::query()
            ->whereIn('delivery_id', $deliveryIds)
            ->where('type', ScanType::InTransit->value)
            ->orderBy('occurred_at')
            ->get(['lat', 'lng', 'occurred_at']);

        foreach ($events as $event) {
            $lat = self::coord($event->lat);
            $lng = self::coord($event->lng);

            if ($lat === null || $lng === null) {
                continue;
            }

            $track[] = [$lat, $lng];
        }

        return $track;
    }

    /**
     * Normaliza una coordenada almacenada como texto a float, o null si no es numérica.
     */
    private static function coord(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicleRoutes::route('/'),
            'view' => ViewVehicleRoute::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // La ruta no se crea como fila: nace por el comando AssembleDailyRoute.
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        // La ruta solo cambia por comandos de ejecución (entregas/fallos), no como fila editable.
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * Progreso legible de la ruta: paradas atendidas / total.
     */
    private static function progress(VehicleRouteRecord $record): string
    {
        $stops = $record->stops;
        $total = $stops->count();
        $settled = $stops->filter(static fn (Model $stop): bool => $stop->getAttribute('status') !== RouteStopStatus::Pending->value)->count();

        return "{$settled}/{$total}";
    }

    /**
     * Opciones de estado de ruta (valor del enum → etiqueta en inglés) para el filtro.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (RouteStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status->value);
        }

        return $options;
    }

    private static function statusLabel(string $state): string
    {
        return match ($state) {
            RouteStatus::Planned->value => __('Planned'),
            RouteStatus::InProgress->value => __('In progress'),
            RouteStatus::Completed->value => __('Completed'),
            default => $state,
        };
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            RouteStatus::Planned->value => 'gray',
            RouteStatus::InProgress->value => 'warning',
            RouteStatus::Completed->value => 'success',
            default => 'gray',
        };
    }

    private static function stopStatusLabel(string $state): string
    {
        return match ($state) {
            RouteStopStatus::Pending->value => __('Pending'),
            RouteStopStatus::Completed->value => __('Delivered'),
            RouteStopStatus::Failed->value => __('Failed'),
            default => $state,
        };
    }

    private static function stopStatusColor(string $state): string
    {
        return match ($state) {
            RouteStopStatus::Pending->value => 'gray',
            RouteStopStatus::Completed->value => 'success',
            RouteStopStatus::Failed->value => 'danger',
            default => 'gray',
        };
    }
}
