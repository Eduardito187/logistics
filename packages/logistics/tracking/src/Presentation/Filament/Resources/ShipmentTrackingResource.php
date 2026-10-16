<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\PlaceShipmentOnRoute\PlaceShipmentOnRoute;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource\Pages\ListShipmentTrackings;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource\Pages\ViewShipmentTracking;

/**
 * Admin del read model de seguimiento (tabla tracking_shipments).
 *
 * Un único modelo Eloquent (TrackingRecord) concentra las tres facetas del contexto: el estado visible
 * del envío, la última posición GPS del camión (last_lat/last_lng/last_position_at) y el token público
 * del enlace de rastreo. Por eso basta un Resource para cubrirlas todas.
 *
 * DATOS LEGIBLES: el envío se muestra por su referencia humana (external_id + cliente) vía la relación
 * Eloquent shipment(), no por el UUID crudo; el estado se muestra como badge legible; el token NO se
 * expone crudo (se enmascara). Los UUID quedan como columnas secundarias (toggleable, ocultas por
 * defecto).
 *
 * FLUJOS TRANSACCIONALES: el seguimiento es una PROYECCIÓN; no se crea ni edita como CRUD. Las
 * operaciones de negocio se exponen como acciones que despachan los casos de uso reales por el
 * CommandBus (respetando invariantes y outbox transaccional): abrir seguimiento (OpenTracking),
 * proyectar un evento de ejecución que avanza el estado visible (ProjectExecutionEvent) e ingerir la
 * posición del camión (UpdateTruckPosition). Por eso crear/editar/borrar directos están deshabilitados.
 *
 * Todo el texto visible va en inglés (regla 5). El integrador registra este Resource en el panel
 * (discoverResources / resources()); el package está en dont-discover, así que no se autodescubre.
 *
 * @extends resource<TrackingRecord>
 */
final class ShipmentTrackingResource extends Resource
{
    use ResolvesUserRoles;

    /**
     * Seguimiento de envíos: visibilidad amplia (tracking:read). Además de la oficina lo ven los roles
     * con seguimiento en campo: chofer, tienda y transportista. El preparador no hace seguimiento.
     *
     * Sobrescribir canAccess() basta: Filament lo usa para ocultar del menú y bloquear la URL (403).
     */
    public static function canAccess(): bool
    {
        return self::authUserHasAnyRoleValue('admin', 'supervisor', 'chofer', 'tienda', 'transportista');
    }

    protected static ?string $model = TrackingRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-map-pin';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationSort(): int
    {
        return 40;
    }

    public static function getNavigationLabel(): string
    {
        return __('Shipment tracking');
    }

    public static function getModelLabel(): string
    {
        return __('Shipment tracking');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Shipment tracking');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Eager-load del envío para que las columnas shipment.* no disparen N+1 en el listado.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('shipment');
    }

    /**
     * Título humano del registro: la referencia del envío, nunca el UUID. Se usa en el breadcrumb del
     * detalle, la búsqueda global y los encabezados de las acciones.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof TrackingRecord) {
            return null;
        }

        return self::shipmentReference($record);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            // Identidad del envío (referencia humana) y token público enmascarado.
            Section::make(__('Shipment'))
                ->description(__('Shipment reference and the public tracking token.'))
                ->schema([
                    TextEntry::make('shipment.external_id')
                        ->label(__('Shipment reference'))
                        ->placeholder(__('Unknown shipment')),
                    TextEntry::make('shipment.customer_name')
                        ->label(__('Customer'))
                        ->placeholder('—'),
                    TextEntry::make('token')
                        ->label(__('Public token'))
                        // El token es el secreto del enlace público: se muestra enmascarado, nunca crudo.
                        ->formatStateUsing(fn (?string $state): string => self::maskToken($state)),
                ])
                ->columns(2),
            // Estado visible del envío y progreso hacia el cliente.
            Section::make(__('Status & ETA'))
                ->description(__('Customer-facing status, estimated arrival and remaining stops.'))
                ->schema([
                    TextEntry::make('status')
                        ->label(__('Visible status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                        ->color(fn (string $state): string => self::statusColor($state)),
                    TextEntry::make('remaining_stops')
                        ->label(__('Stops remaining'))
                        ->helperText(__('Stops the truck serves before reaching this customer.')),
                    TextEntry::make('route_position')
                        ->label(__('Route position'))
                        ->placeholder(__('Not on a route yet'))
                        ->helperText(__('Order of this shipment in the published truck route.')),
                    TextEntry::make('eta')
                        ->label(__('ETA'))
                        ->dateTime()
                        ->placeholder(__('Not available')),
                ])
                ->columns(3),
            // Última posición GPS reportada por el camión.
            Section::make(__('Last GPS position'))
                ->description(__('Latest truck position ingested for this shipment.'))
                ->schema([
                    TextEntry::make('last_lat')
                        ->label(__('Last latitude'))
                        ->placeholder(__('No position yet')),
                    TextEntry::make('last_lng')
                        ->label(__('Last longitude'))
                        ->placeholder(__('No position yet')),
                    TextEntry::make('last_position_at')
                        ->label(__('Last position at'))
                        ->dateTime()
                        ->placeholder(__('No position yet')),
                ])
                ->columns(3),
            // Metadatos de auditoría de la proyección.
            Section::make(__('Audit'))
                ->schema([
                    TextEntry::make('updated_at')
                        ->label(__('Updated at'))
                        ->dateTime(),
                    TextEntry::make('created_at')
                        ->label(__('Created at'))
                        ->dateTime(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Referencia humana del envío (relación Eloquent), en vez del UUID crudo.
                TextColumn::make('shipment.external_id')
                    ->label(__('Shipment'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (TrackingRecord $record): ?string => $record->shipment?->customer_name)
                    ->placeholder(__('Unknown shipment')),
                // Token público: enmascarado, secundario y oculto por defecto. Nunca crudo ni copiable.
                TextColumn::make('token')
                    ->label(__('Public token'))
                    ->formatStateUsing(fn (?string $state): string => self::maskToken($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('remaining_stops')
                    ->label(__('Stops remaining'))
                    ->numeric()
                    ->sortable()
                    // Lectura humana: cuántas paradas faltan antes de la del cliente.
                    ->tooltip(__('Stops the truck serves before reaching this customer.')),
                TextColumn::make('route_position')
                    ->label(__('Route position'))
                    ->numeric()
                    ->sortable()
                    ->placeholder(__('Not on a route yet')),
                TextColumn::make('eta')
                    ->label(__('ETA'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('last_position_at')
                    ->label(__('GPS'))
                    ->boolean()
                    // Verdadero cuando ya hay al menos una posición GPS reportada.
                    ->state(fn (TrackingRecord $record): bool => $record->last_position_at !== null)
                    ->trueIcon('heroicon-o-map-pin')
                    ->falseIcon('heroicon-o-no-symbol'),
                TextColumn::make('last_position_at')
                    ->label(__('Last position'))
                    ->dateTime()
                    ->placeholder(__('No position'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Visible status'))
                    ->native(false)
                    ->options(self::statusOptions()),
                // Acota a envíos terminados (entregado/fallido) o en curso.
                SelectFilter::make('lifecycle')
                    ->label(__('Lifecycle'))
                    ->native(false)
                    ->options([
                        'in_progress' => __('In progress'),
                        'terminal' => __('Terminal'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $value): Builder => $value === 'terminal'
                            ? $q->whereIn('status', self::terminalStatusValues())
                            : $q->whereNotIn('status', self::terminalStatusValues()),
                    )),
                // Solo los envíos con al menos una posición GPS reportada.
                Filter::make('has_position')
                    ->label(__('With GPS position'))
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('last_position_at')),
            ])
            ->recordActions([
                ViewAction::make(),
                self::placeOnRouteAction(),
                self::projectExecutionEventAction(),
                self::updateTruckPositionAction(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListShipmentTrackings::route('/'),
            'view' => ViewShipmentTracking::route('/{record}'),
        ];
    }

    /**
     * FLUJO: abrir el seguimiento de un envío todavía no seguido.
     *
     * Despacha OpenTracking por el CommandBus (el handler genera el token público y es idempotente por
     * envío). El select de envío es buscable y se alimenta de los envíos reales que aún no tienen
     * seguimiento. Pensado para la cabecera del listado.
     */
    public static function openTrackingAction(): Action
    {
        return Action::make('openTracking')
            ->label(__('Open tracking'))
            ->icon('heroicon-o-plus-circle')
            ->modalHeading(__('Open tracking for a shipment'))
            ->modalDescription(__('Generate a public tracking link for a shipment that is not tracked yet.'))
            ->modalSubmitActionLabel(__('Open tracking'))
            ->schema([
                Select::make('shipment_id')
                    ->label(__('Shipment'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->helperText(__('Only shipments without tracking yet are listed.'))
                    ->getSearchResultsUsing(static fn (string $search): array => ShipmentRecord::query()
                        ->whereNotIn('id', TrackingRecord::query()->select('shipment_id'))
                        ->where(static function (Builder $query) use ($search): void {
                            $query->where('external_id', 'like', "%{$search}%")
                                ->orWhere('customer_name', 'like', "%{$search}%");
                        })
                        ->orderBy('external_id')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(static fn (ShipmentRecord $record): array => [
                            $record->id => "{$record->external_id} — {$record->customer_name}",
                        ])
                        ->all())
                    ->getOptionLabelUsing(static function (string $value): ?string {
                        $record = ShipmentRecord::query()->find($value);

                        return $record instanceof ShipmentRecord
                            ? "{$record->external_id} — {$record->customer_name}"
                            : null;
                    }),
                TextInput::make('remaining_stops')
                    ->label(__('Remaining stops'))
                    ->helperText(__('Stops left before this shipment reaches the customer.'))
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
            ])
            ->action(static function (array $data): void {
                app(CommandBus::class)->dispatch(new OpenTracking(
                    shipmentId: (string) $data['shipment_id'],
                    remainingStops: (int) $data['remaining_stops'],
                ));

                Notification::make()
                    ->title(__('Tracking opened'))
                    ->body(__('A public tracking link was generated for the shipment.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * FLUJO: ubicar el envío en la ruta publicada del camión.
     *
     * Despacha PlaceShipmentOnRoute por el CommandBus: el handler fija la ruta y la posición (1-based) y
     * el agregado deriva las paradas restantes antes de la del cliente. Normalmente lo dispara el relay
     * al publicarse el plan (planning.plan_published); acá se expone como acción manual de operaciones.
     */
    public static function placeOnRouteAction(): Action
    {
        return Action::make('placeOnRoute')
            ->label(__('Place on route'))
            ->icon('heroicon-o-queue-list')
            ->color('gray')
            // Un seguimiento terminal (entregado/fallido) ya no se reubica en la ruta.
            ->visible(static fn (TrackingRecord $record): bool => !self::isTerminalStatus($record->status))
            ->modalHeading(__('Place shipment on the truck route'))
            ->modalDescription(__('Set the route and the stop position; remaining stops are derived from it.'))
            ->modalSubmitActionLabel(__('Place on route'))
            ->schema([
                TextInput::make('route_id')
                    ->label(__('Route'))
                    ->helperText(__('Identifier of the published truck route (plan).'))
                    ->required(),
                TextInput::make('route_position')
                    ->label(__('Route position'))
                    ->helperText(__('Order of this shipment in the route (1 is the first stop).'))
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
            ])
            ->action(static function (TrackingRecord $record, array $data): void {
                app(CommandBus::class)->dispatch(new PlaceShipmentOnRoute(
                    shipmentId: (string) $record->shipment_id,
                    routeId: (string) $data['route_id'],
                    routePosition: (int) $data['route_position'],
                ));

                Notification::make()
                    ->title(__('Shipment placed on route'))
                    ->body(__('Remaining stops were recomputed from the route position.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * FLUJO: proyectar un evento de ejecución que avanza el estado visible del seguimiento.
     *
     * Despacha ProjectExecutionEvent por el CommandBus con el tipo de evento de ejecución elegido. El
     * handler es tolerante: si el evento no mapea a un estado visible, no cambia nada. Es una acción de
     * fila: el envío sale del propio registro.
     */
    public static function projectExecutionEventAction(): Action
    {
        return Action::make('projectExecutionEvent')
            ->label(__('Advance status'))
            ->icon('heroicon-o-forward')
            ->color('info')
            // Un seguimiento terminal (entregado/fallido) ya no avanza.
            ->visible(static fn (TrackingRecord $record): bool => !self::isTerminalStatus($record->status))
            ->modalHeading(__('Advance visible status'))
            ->modalDescription(__('Project an execution event onto this shipment tracking.'))
            ->modalSubmitActionLabel(__('Project event'))
            ->schema([
                Select::make('execution_event_type')
                    ->label(__('Execution event'))
                    ->required()
                    ->native(false)
                    ->options(self::executionEventOptions()),
            ])
            ->action(static function (TrackingRecord $record, array $data): void {
                app(CommandBus::class)->dispatch(new ProjectExecutionEvent(
                    executionEventType: (string) $data['execution_event_type'],
                    shipmentId: (string) $record->shipment_id,
                ));

                Notification::make()
                    ->title(__('Execution event projected'))
                    ->body(__('The visible status was updated from the execution event.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * FLUJO: ingerir la última posición GPS del camión que lleva el envío.
     *
     * Despacha UpdateTruckPosition por el CommandBus (el handler arma el VO y valida rangos). Acción de
     * fila: el envío sale del propio registro.
     */
    public static function updateTruckPositionAction(): Action
    {
        return Action::make('updateTruckPosition')
            ->label(__('Report GPS position'))
            ->icon('heroicon-o-map-pin')
            ->modalHeading(__('Report truck position'))
            ->modalDescription(__('Ingest the latest known truck position for this shipment.'))
            ->modalSubmitActionLabel(__('Report position'))
            ->schema([
                TextInput::make('latitude')
                    ->label(__('Latitude'))
                    ->required()
                    ->numeric()
                    ->minValue(-90)
                    ->maxValue(90)
                    ->step('any'),
                TextInput::make('longitude')
                    ->label(__('Longitude'))
                    ->required()
                    ->numeric()
                    ->minValue(-180)
                    ->maxValue(180)
                    ->step('any'),
                DateTimePicker::make('reported_at')
                    ->label(__('Reported at'))
                    ->required()
                    ->seconds(false)
                    ->native(false)
                    ->default(now()),
            ])
            ->action(static function (TrackingRecord $record, array $data): void {
                app(CommandBus::class)->dispatch(new UpdateTruckPosition(
                    shipmentId: (string) $record->shipment_id,
                    latitude: (float) $data['latitude'],
                    longitude: (float) $data['longitude'],
                    reportedAt: self::toIso8601((string) $data['reported_at']),
                ));

                Notification::make()
                    ->title(__('Position reported'))
                    ->body(__('The latest truck position was ingested for this shipment.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Referencia humana del envío para mostrar en vez del UUID. Usa la relación cargada; si falta el
     * envío, cae al identificador interno para no mostrar nada vacío.
     */
    private static function shipmentReference(TrackingRecord $record): string
    {
        $shipment = $record->shipment;
        if ($shipment instanceof ShipmentRecord) {
            return "{$shipment->external_id} — {$shipment->customer_name}";
        }

        return __('Unknown shipment');
    }

    /**
     * Enmascara el token público del enlace de rastreo: nunca se muestra crudo en el back office. Deja
     * ver solo los últimos cuatro caracteres como pista de verificación.
     */
    private static function maskToken(?string $token): string
    {
        if ($token === null || $token === '') {
            return '—';
        }

        $visible = substr($token, -4);

        return '••••••'.$visible;
    }

    /**
     * Normaliza el instante del datetime-picker a ISO 8601, que es lo que espera el comando.
     */
    private static function toIso8601(string $value): string
    {
        return Carbon::parse($value)->toIso8601String();
    }

    /**
     * Opciones del flujo "Advance status": tipo de evento de ejecución => etiqueta del estado visible
     * al que lleva. Son los tipos normalizados reales que entiende VisibleStatus::fromExecutionEvent.
     *
     * @return array<string, string>
     */
    private static function executionEventOptions(): array
    {
        return [
            'execution.shipment_prepared' => __('Prepared'),
            'execution.shipment_loaded' => __('On the way'),
            'execution.shipment_arriving' => __('Arriving'),
            'execution.shipment_delivered' => __('Delivered'),
            'execution.delivery_failed' => __('Failed'),
        ];
    }

    /**
     * Opciones del filtro de estado: valor del enum => etiqueta legible en inglés.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (VisibleStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status->value);
        }

        return $options;
    }

    /**
     * Valores de los estados terminales (entregado y fallido) para filtrar por ciclo de vida.
     *
     * @return list<string>
     */
    private static function terminalStatusValues(): array
    {
        $values = [];
        foreach (VisibleStatus::cases() as $status) {
            if ($status->isTerminal()) {
                $values[] = $status->value;
            }
        }

        return $values;
    }

    /**
     * ¿El valor crudo de estado corresponde a un estado terminal (entregado/fallido)?
     */
    private static function isTerminalStatus(string $state): bool
    {
        $status = VisibleStatus::tryFrom($state);

        return $status !== null && $status->isTerminal();
    }

    /**
     * Etiqueta humana en inglés para un estado visible.
     */
    private static function statusLabel(string $state): string
    {
        return match ($state) {
            VisibleStatus::Registered->value => __('Registered'),
            VisibleStatus::Prepared->value => __('Prepared'),
            VisibleStatus::OnTheWay->value => __('On the way'),
            VisibleStatus::Arriving->value => __('Arriving'),
            VisibleStatus::Delivered->value => __('Delivered'),
            VisibleStatus::Failed->value => __('Failed'),
            default => $state,
        };
    }

    /**
     * Color del badge por estado, siguiendo la paleta semántica de Filament.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            VisibleStatus::Delivered->value => 'success',
            VisibleStatus::Failed->value => 'danger',
            VisibleStatus::OnTheWay->value, VisibleStatus::Arriving->value => 'info',
            VisibleStatus::Prepared->value => 'warning',
            default => 'gray',
        };
    }
}
