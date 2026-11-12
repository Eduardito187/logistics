<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Logistics\Carriers\Application\Command\AssignShipmentsToCarrierTrip\AssignShipmentsToCarrierTrip;
use Logistics\Carriers\Application\Command\CancelCarrierTrip\CancelCarrierTrip;
use Logistics\Carriers\Application\Command\CloseCarrierTrip\CloseCarrierTrip;
use Logistics\Carriers\Application\Command\RemoveShipmentFromCarrierTrip\RemoveShipmentFromCarrierTrip;
use Logistics\Carriers\Application\Command\StartCarrierTrip\StartCarrierTrip;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierTripRecord;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource\Pages\CreateCarrierTrip;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource\Pages\ListCarrierTrips;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource\Pages\ViewCarrierTrip;
use Logistics\Carriers\Presentation\Filament\Support\TripLookups;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Exception\DomainException;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shared\Presentation\Filament\Concerns\PlainNavigationLabel;

/**
 * Admin de viajes de transportista: la oficina programa un viaje para un transportista, arma su carga
 * (envíos) y puede iniciarlo/cerrarlo/cancelarlo en su nombre (auditoría app #4).
 *
 * Igual que el resto del contexto: Filament LEE sobre el record Eloquent y TODA mutación despacha un
 * comando al CommandBus (CQRS), nunca escribe el record. Fuera del menú: el viaje depende del
 * transportista; su hogar es la ficha 360 del transportista (sección de gestión), que enlaza a la lista
 * filtrada por `?carrier=<id>`.
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6); nunca UUIDs visibles.
 */
final class CarrierTripResource extends Resource
{
    use OfficeOnly;
    use PlainNavigationLabel;

    protected static ?string $model = CarrierTripRecord::class;

    /**
     * Estados del ciclo de vida (mismos valores que CarrierTripStatus del dominio).
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationSort(): int
    {
        return 53;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-truck';
    }

    public static function getModelLabel(): string
    {
        return __('Carrier trip');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Carrier trips');
    }

    public static function getNavigationLabel(): string
    {
        return __('Carrier trips');
    }

    /**
     * Título humano: transportista · origen → destino · fecha (nunca el UUID).
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof CarrierTripRecord) {
            return null;
        }

        $carrier = $record->carrier->name ?? __('Carrier');
        $route = TripLookups::nodeName($record->corridor_from).' → '.TripLookups::nodeName($record->corridor_to);

        return "{$carrier} · {$route} · ".$record->scheduled_at->format('Y-m-d H:i');
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return array_map(static fn (string $label): string => __($label), self::STATUSES);
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'scheduled' => 'warning',
            'in_progress' => 'info',
            'closed' => 'success',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Formulario de alta: transportista activo, corredor (nodos por nombre), fecha y carga inicial.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Trip'))
                ->schema([
                    Select::make('carrier_id')
                        ->label(__('Carrier'))
                        ->relationship(
                            'carrier',
                            'name',
                            static fn (Builder $query): Builder => $query->where('status', 'active'),
                        )
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->preload(),
                    DateTimePicker::make('scheduled_at')
                        ->label(__('Scheduled departure'))
                        ->seconds(false)
                        ->required(),
                    Select::make('corridor_from')
                        ->label(__('Pickup node'))
                        ->options(static fn (): array => TripLookups::nodeOptions())
                        ->required()
                        ->native(false)
                        ->searchable(),
                    Select::make('corridor_to')
                        ->label(__('Destination node'))
                        ->options(static fn (): array => TripLookups::nodeOptions())
                        ->required()
                        ->different('corridor_from')
                        ->native(false)
                        ->searchable(),
                    TextInput::make('reference')
                        ->label(__('Reference'))
                        ->helperText(__('Optional: manifest or waybill number.'))
                        ->maxLength(255),
                ])
                ->columns(2),
            Section::make(__('Load'))
                ->schema([
                    self::shipmentPicker('shipment_ids'),
                ]),
        ]);
    }

    /**
     * Select buscable de envíos cargables (no en otro viaje vigente), por pedido o cliente.
     */
    public static function shipmentPicker(string $name): Select
    {
        return Select::make($name)
            ->label(__('Shipments'))
            ->helperText(__('Search by order number or customer. Shipments already on an active trip are excluded.'))
            ->multiple()
            ->native(false)
            ->searchable()
            ->getSearchResultsUsing(static fn (string $search): array => TripLookups::searchLoadableShipments($search))
            ->getOptionLabelsUsing(static fn (array $values): array => TripLookups::shipmentLabels(
                array_values(array_filter($values, static fn (mixed $v): bool => \is_string($v))),
            ));
    }

    public static function table(Table $table): Table
    {
        return $table
            // Desde la ficha del transportista: `?carrier=<id>` acota la lista a ese transportista.
            ->modifyQueryUsing(static function (Builder $query): Builder {
                /** @var Builder<CarrierTripRecord> $query */
                $carrier = request()->query('carrier');

                return \is_string($carrier) && $carrier !== '' ? $query->where('carrier_id', $carrier) : $query;
            })
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                TextColumn::make('carrier.name')
                    ->label(__('Carrier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('corridor_from')
                    ->label(__('Pickup node'))
                    ->formatStateUsing(static fn (?string $state): string => TripLookups::nodeName($state)),
                TextColumn::make('corridor_to')
                    ->label(__('Destination node'))
                    ->formatStateUsing(static fn (?string $state): string => TripLookups::nodeName($state)),
                TextColumn::make('scheduled_at')
                    ->label(__('Scheduled departure'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('shipments_count')
                    ->label(__('Shipments'))
                    ->counts('shipments')
                    ->sortable(),
                TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
            ])
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label(__('Carrier'))
                    ->native(false)
                    ->searchable()
                    ->options(static fn (): array => DB::table('carriers')->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statusOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                self::startAction(),
                self::closeAction(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Trip'))
                ->schema([
                    TextEntry::make('carrier.name')
                        ->label(__('Carrier')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                    TextEntry::make('corridor_from')
                        ->label(__('Pickup node'))
                        ->formatStateUsing(static fn (?string $state): string => TripLookups::nodeName($state)),
                    TextEntry::make('corridor_to')
                        ->label(__('Destination node'))
                        ->formatStateUsing(static fn (?string $state): string => TripLookups::nodeName($state)),
                    TextEntry::make('scheduled_at')
                        ->label(__('Scheduled departure'))
                        ->dateTime('Y-m-d H:i'),
                    TextEntry::make('reference')
                        ->label(__('Reference'))
                        ->placeholder('—'),
                    TextEntry::make('started_at')
                        ->label(__('Started at'))
                        ->dateTime('Y-m-d H:i')
                        ->placeholder(__('Not started yet')),
                    TextEntry::make('closed_at')
                        ->label(__('Closed at'))
                        ->dateTime('Y-m-d H:i')
                        ->placeholder(__('Not closed yet')),
                ])
                ->columns(2),
            Section::make(__('Load'))
                ->schema([
                    RepeatableEntry::make('load')
                        ->label(__('Shipments'))
                        ->state(static fn (CarrierTripRecord $record): array => TripLookups::load($record->id))
                        ->placeholder(__('No shipments assigned yet'))
                        ->schema([
                            TextEntry::make('shipment')
                                ->label(__('Order')),
                            TextEntry::make('destination')
                                ->label(__('Destination')),
                            TextEntry::make('units')
                                ->label(__('Products to pick up')),
                            TextEntry::make('products')
                                ->label(__('Products'))
                                ->columnSpanFull(),
                        ])
                        ->columns(3),
                ]),
        ]);
    }

    /**
     * Iniciar el viaje en nombre del transportista (visible si está programado).
     */
    public static function startAction(): Action
    {
        return Action::make('start')
            ->label(__('Start trip'))
            ->icon('heroicon-o-play')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Start trip'))
            ->visible(static fn (CarrierTripRecord $record): bool => $record->status === 'scheduled')
            ->action(static function (CarrierTripRecord $record): void {
                self::dispatch(new StartCarrierTrip(tripId: $record->id, actorId: self::actorId()), __('Trip started'));
            });
    }

    /**
     * Cerrar el viaje en nombre del transportista (visible si está en ruta).
     */
    public static function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('Close trip'))
            ->icon('heroicon-o-flag')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Close trip'))
            ->visible(static fn (CarrierTripRecord $record): bool => $record->status === 'in_progress')
            ->action(static function (CarrierTripRecord $record): void {
                self::dispatch(new CloseCarrierTrip(tripId: $record->id, actorId: self::actorId()), __('Trip closed'));
            });
    }

    /**
     * Agregar envíos a la carga (visible si está programado).
     */
    public static function addShipmentsAction(): Action
    {
        return Action::make('addShipments')
            ->label(__('Add shipments'))
            ->icon('heroicon-o-plus-circle')
            ->visible(static fn (CarrierTripRecord $record): bool => $record->status === 'scheduled')
            ->schema([
                self::shipmentPicker('shipment_ids')->required(),
            ])
            ->modalHeading(__('Add shipments to trip'))
            ->action(static function (CarrierTripRecord $record, array $data): void {
                $ids = \is_array($data['shipment_ids'] ?? null)
                    ? array_values(array_filter($data['shipment_ids'], static fn (mixed $v): bool => \is_string($v)))
                    : [];

                self::dispatch(new AssignShipmentsToCarrierTrip(tripId: $record->id, shipmentIds: $ids), __('Shipments added'));
            });
    }

    /**
     * Quitar un envío de la carga (visible si está programado). Opciones = la carga actual, por nombre.
     */
    public static function removeShipmentAction(): Action
    {
        return Action::make('removeShipment')
            ->label(__('Remove shipment'))
            ->icon('heroicon-o-minus-circle')
            ->color('danger')
            ->visible(static fn (CarrierTripRecord $record): bool => $record->status === 'scheduled')
            ->schema([
                Select::make('shipment_id')
                    ->label(__('Shipment'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->options(static fn (CarrierTripRecord $record): array => TripLookups::shipmentLabels(
                        array_values(array_map(
                            static fn (mixed $id): string => \is_string($id) ? $id : '',
                            $record->shipments()->pluck('shipment_id')->all(),
                        )),
                    )),
            ])
            ->modalHeading(__('Remove shipment from trip'))
            ->action(static function (CarrierTripRecord $record, array $data): void {
                self::dispatch(
                    new RemoveShipmentFromCarrierTrip(tripId: $record->id, shipmentId: (string) ($data['shipment_id'] ?? '')),
                    __('Shipment removed'),
                );
            });
    }

    /**
     * Cancelar un viaje programado.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel trip'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(static fn (CarrierTripRecord $record): bool => $record->status === 'scheduled')
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->maxLength(1000),
            ])
            ->modalHeading(__('Cancel trip'))
            ->action(static function (CarrierTripRecord $record, array $data): void {
                $reason = $data['reason'] ?? null;
                self::dispatch(
                    new CancelCarrierTrip(tripId: $record->id, reason: \is_string($reason) ? $reason : null),
                    __('Trip cancelled'),
                );
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('carrier');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCarrierTrips::route('/'),
            'create' => CreateCarrierTrip::route('/create'),
            'view' => ViewCarrierTrip::route('/{record}'),
        ];
    }

    /**
     * Despacha el comando y notifica. Una regla de negocio violada (transición inválida, envío ya en otro
     * viaje) se muestra como aviso en inglés, no como 500; el detalle técnico queda en el dominio.
     */
    private static function dispatch(Command $command, string $successTitle): void
    {
        try {
            app(CommandBus::class)->dispatch($command);
        } catch (DomainException) {
            Notification::make()
                ->title(__('Action not allowed'))
                ->body(__('The trip cannot change in its current state, or a shipment is missing or already on another active trip.'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }

    private static function actorId(): ?string
    {
        $id = auth()->id();

        return \is_int($id) || \is_string($id) ? (string) $id : null;
    }
}
