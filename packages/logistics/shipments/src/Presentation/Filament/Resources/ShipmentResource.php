<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources;

use App\Filament\Pages\CustomerProfile;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use Logistics\Shipments\Application\Command\GeocodeShipmentAddress\GeocodeShipmentAddress;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages\EditShipment;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages\ListShipments;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\RelationManagers\LinesRelationManager;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\RelationManagers\UnitsRelationManager;

/**
 * Admin de pedidos recibidos: ver/editar los datos administrables y re-geocodificar la dirección.
 *
 * Nota de arquitectura: Filament opera sobre el modelo Eloquent (ShipmentRecord) por diseño del
 * framework. Las ESCRITURAS de negocio (re-geocodificar) se despachan por el CommandBus para
 * respetar el flujo Presentation → Application (transacción + outbox); la edición directa se limita
 * a campos administrativos. Todo texto visible va en inglés (regla CLAUDE.md #5).
 */
final class ShipmentResource extends Resource
{
    protected static ?string $model = ShipmentRecord::class;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Received orders');
    }

    public static function getModelLabel(): string
    {
        return __('Received order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Received orders');
    }

    /**
     * Título humano del pedido para buscador global y encabezados: "fuente · referencia — destinatario",
     * nunca el UUID crudo (regla de datos legibles del admin).
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof ShipmentRecord) {
            return null;
        }

        $source = self::sources()[$record->source] ?? $record->source;

        return "{$source} · {$record->external_id} — {$record->customer_name}";
    }

    /**
     * Atributos sobre los que busca el buscador global: referencia y destinatario (campos humanos).
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['external_id', 'customer_name'];
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-inbox-arrow-down';
    }

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    /**
     * Fuentes de origen del pedido. Las claves (valores del enum OrderSource) quedan crudas; solo las
     * etiquetas visibles pasan por __() para poder traducirse.
     *
     * @return array<string, string>
     */
    private static function sources(): array
    {
        return [
            'SAP' => __('SAP'),
            'MAGENTO' => __('Magento'),
            'STORE' => __('Store'),
            'MANUAL' => __('Manual'),
            'EXCEL' => __('Excel'),
        ];
    }

    /**
     * Niveles de confianza de la dirección. Las claves (enum AddressConfidenceLevel) quedan crudas;
     * solo las etiquetas visibles pasan por __().
     *
     * @return array<string, string>
     */
    private static function confidenceLevels(): array
    {
        return [
            'HIGH' => __('High'),
            'MEDIUM' => __('Medium'),
            'LOW' => __('Low'),
        ];
    }

    /**
     * Estados del ciclo de vida. Las claves (valores del enum ShipmentStatus) quedan crudas; solo las
     * etiquetas visibles pasan por __().
     *
     * @return array<string, string>
     */
    private static function statuses(): array
    {
        return [
            'RECEIVED' => __('Received'),
            'PLANNED' => __('Planned'),
            'IN_EXECUTION' => __('In execution'),
            'DELIVERED' => __('Delivered'),
            'FAILED' => __('Failed'),
            'CANCELLED' => __('Cancelled'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('external_id')
                ->label(__('External reference'))
                ->disabled()
                ->dehydrated(false),
            Select::make('source')
                ->label(__('Source'))
                ->options(self::sources())
                ->native(false)
                ->disabled()
                ->dehydrated(false),
            Select::make('status')
                ->label(__('Status'))
                ->options(self::statuses())
                ->native(false)
                ->disabled()
                ->dehydrated(false),
            TextInput::make('customer_name')
                ->label(__('Customer name'))
                ->required()
                ->maxLength(255),
            TextInput::make('customer_document_id')
                ->label(__('Identity document'))
                ->maxLength(40),
            TextInput::make('customer_phone')
                ->label(__('Phone'))
                ->tel()
                ->maxLength(40),
            TextInput::make('customer_email')
                ->label(__('Email'))
                ->email()
                ->maxLength(180),
            Textarea::make('address_text')
                ->label(__('Delivery address'))
                ->required()
                ->rows(2)
                ->maxLength(1000),
            Textarea::make('address_reference')
                ->label(__('Address reference'))
                ->rows(2)
                ->maxLength(1000),
            TextInput::make('lat')
                ->label(__('Latitude'))
                ->numeric()
                ->minValue(-90)
                ->maxValue(90),
            TextInput::make('lng')
                ->label(__('Longitude'))
                ->numeric()
                ->minValue(-180)
                ->maxValue(180),
            TextInput::make('address_confidence')
                ->label(__('Address confidence score'))
                ->disabled()
                ->dehydrated(false),
            TextInput::make('address_confidence_level')
                ->label(__('Address confidence level'))
                ->disabled()
                ->dehydrated(false),
        ]);
    }

    /**
     * Vista de detalle del pedido: todo legible y enlazado. Resumen del pedido, cliente, dirección
     * completa con su score, mapa de la ubicación de entrega (Leaflet + OpenStreetMap), líneas de
     * producto (con imagen y precio) y el historial de otros pedidos del mismo cliente. Todo el texto
     * visible en inglés dentro de __() (regla 5 de CLAUDE.md).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Order summary'))
                ->icon('heroicon-o-inbox-arrow-down')
                ->schema([
                    TextEntry::make('external_id')
                        ->label(__('External reference'))
                        ->copyable(),
                    TextEntry::make('source')
                        ->label(__('Source'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => self::sources()[$state] ?? $state),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => self::statuses()[$state] ?? $state)
                        ->color(static fn (string $state): string => match ($state) {
                            'DELIVERED' => 'success',
                            'IN_EXECUTION', 'PLANNED' => 'info',
                            'FAILED', 'CANCELLED' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('created_at')
                        ->label(__('Received at'))
                        ->dateTime()
                        ->placeholder('—'),
                ])
                // Columnas que se adaptan al ancho: 1 en móvil, 2 en tablet, 4 en escritorio.
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4]),

            Section::make(__('Customer'))
                ->icon('heroicon-o-user')
                ->description(__('Basic customer details on this order.'))
                ->schema([
                    TextEntry::make('customer_name')
                        ->label(__('Customer name'))
                        ->icon('heroicon-o-user')
                        ->weight('bold')
                        ->url(static fn (ShipmentRecord $record): string => CustomerProfile::getUrl(['customer' => $record->customer_name]))
                        ->openUrlInNewTab()
                        ->color('primary'),
                    TextEntry::make('customer_document_id')
                        ->label(__('Identity document'))
                        ->icon('heroicon-o-identification')
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('customer_phone')
                        ->label(__('Phone'))
                        ->icon('heroicon-o-phone')
                        ->copyable()
                        ->url(static fn (ShipmentRecord $record): ?string => $record->customer_phone !== null ? 'tel:'.$record->customer_phone : null)
                        ->placeholder('—'),
                    TextEntry::make('customer_email')
                        ->label(__('Email'))
                        ->icon('heroicon-o-envelope')
                        ->copyable()
                        ->url(static fn (ShipmentRecord $record): ?string => $record->customer_email !== null ? 'mailto:'.$record->customer_email : null)
                        ->placeholder('—'),
                    TextEntry::make('customer_profile_link')
                        ->hiddenLabel()
                        ->state(__('View full profile'))
                        ->badge()
                        ->icon('heroicon-o-identification')
                        ->color('primary')
                        ->url(static fn (ShipmentRecord $record): string => CustomerProfile::getUrl(['customer' => $record->customer_name]))
                        ->openUrlInNewTab()
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4]),

            Section::make(__('Delivery address'))
                ->icon('heroicon-o-map-pin')
                ->schema([
                    TextEntry::make('address_text')
                        ->label(__('Delivery address'))
                        ->icon('heroicon-o-map-pin')
                        ->columnSpanFull(),
                    TextEntry::make('address_reference')
                        ->label(__('Address reference'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('lat')
                        ->label(__('Latitude'))
                        ->placeholder('—'),
                    TextEntry::make('lng')
                        ->label(__('Longitude'))
                        ->placeholder('—'),
                    TextEntry::make('address_confidence')
                        ->label(__('Address confidence'))
                        ->badge()
                        ->formatStateUsing(static fn (int | string $state, ShipmentRecord $record): string => "{$state} · ".(self::confidenceLevels()[$record->address_confidence_level] ?? $record->address_confidence_level))
                        ->color(static fn (ShipmentRecord $record): string => match ($record->address_confidence_level) {
                            'HIGH' => 'success',
                            'MEDIUM' => 'warning',
                            default => 'danger',
                        }),
                ])
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4]),

            Section::make(__('Delivery slot'))
                ->icon('heroicon-o-calendar-days')
                ->schema([
                    TextEntry::make('delivery_date')
                        ->label(__('Delivery date'))
                        ->placeholder(__('Not scheduled')),
                    TextEntry::make('delivery_window_start')
                        ->label(__('Window start'))
                        ->placeholder('—'),
                    TextEntry::make('delivery_window_end')
                        ->label(__('Window end'))
                        ->placeholder('—'),
                ])
                ->columns(['default' => 1, 'sm' => 3]),

            Section::make(__('Delivery location'))
                ->icon('heroicon-o-map')
                ->schema([
                    ViewEntry::make('delivery_map')
                        ->hiddenLabel()
                        ->view('logistics-shipments::filament.shipment-delivery-map'),
                ]),

            Section::make(__('Order lines'))
                ->icon('heroicon-o-shopping-bag')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->schema([
                            ImageEntry::make('product_image_url')
                                ->label(__('Image'))
                                ->square()
                                ->imageSize(56)
                                ->checkFileExistence(false)
                                // Sin URL → placeholder; URL externa que responde 404 → onerror la cambia
                                // por el placeholder. Nunca una imagen rota ni un hueco en blanco.
                                ->defaultImageUrl(ImageFallback::placeholder('box'))
                                ->extraImgAttributes(ImageFallback::onError('box')),
                            TextEntry::make('sku')
                                ->label(__('SKU')),
                            TextEntry::make('product_name')
                                ->label(__('Product name')),
                            TextEntry::make('unit_price')
                                ->label(__('Unit price'))
                                ->money(static fn (ShipmentLineRecord $record): string => $record->unit_price_currency ?? 'BOB')
                                ->placeholder('—'),
                            TextEntry::make('quantity')
                                ->label(__('Quantity'))
                                ->numeric(),
                        ])
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 5]),
                ]),

            Section::make(__('Other orders from this customer'))
                ->icon('heroicon-o-clock')
                ->collapsible()
                ->schema([
                    ViewEntry::make('customer_orders')
                        ->hiddenLabel()
                        ->view('logistics-shipments::filament.shipment-customer-orders'),
                ]),
        ]);
    }

    /**
     * Otros pedidos del mismo cliente (mismo customer_name), excluyendo el actual, para armar el
     * historial enlazado en la vista de detalle. Se limita y ordena por fecha de recepción descendente.
     *
     * @return Collection<int, ShipmentRecord>
     */
    public static function otherOrdersFor(ShipmentRecord $record): Collection
    {
        return ShipmentRecord::query()
            ->where('customer_name', $record->customer_name)
            ->whereKeyNot($record->getKey())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('external_id')
                    ->label(__('External reference'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::sources()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label(__('Customer name'))
                    ->searchable()
                    ->limit(40),
                TextColumn::make('address_text')
                    ->label(__('Delivery address'))
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('address_confidence')
                    ->label(__('Confidence'))
                    ->badge()
                    // Score de la dirección como badge "score · level", con color según el nivel de dominio.
                    ->formatStateUsing(static fn (int | string $state, ShipmentRecord $record): string => "{$state} · ".(self::confidenceLevels()[$record->address_confidence_level] ?? $record->address_confidence_level))
                    ->color(static fn (ShipmentRecord $record): string => match ($record->address_confidence_level) {
                        'HIGH' => 'success',
                        'MEDIUM' => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statuses()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('delivery_date')
                    ->label(__('Delivery slot'))
                    ->placeholder(__('Not scheduled'))
                    ->formatStateUsing(static function (string $state, $record): string {
                        $window = $record->delivery_window_start !== null && $record->delivery_window_end !== null
                            ? " {$record->delivery_window_start}–{$record->delivery_window_end}"
                            : '';

                        return $state.$window;
                    })
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label(__('Source'))
                    ->options(self::sources())
                    ->native(false),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statuses())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::reGeocodeAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ])
            ->defaultSort('external_id');
    }

    /**
     * Acción de re-geocodificación: recalcula la confianza de la dirección despachando el comando
     * por el CommandBus (nunca tocando Infrastructure ni el dominio directamente).
     */
    private static function reGeocodeAction(): Action
    {
        return Action::make('reGeocode')
            ->label(__('Re-geocode'))
            ->icon('heroicon-o-map-pin')
            ->requiresConfirmation()
            ->modalHeading(__('Re-geocode address'))
            ->modalDescription(__('Recalculate the address confidence for this order.'))
            ->action(function (ShipmentRecord $record): void {
                /** @var CommandBus $commandBus */
                $commandBus = app(CommandBus::class);
                $commandBus->dispatch(new GeocodeShipmentAddress((string) $record->getKey()));

                Notification::make()
                    ->title(__('Address re-geocoded'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Managers de relaciones: líneas de producto y bultos del pedido, embebidos en la vista/edición.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            UnitsRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'view' => ViewShipment::route('/{record}'),
            'edit' => EditShipment::route('/{record}/edit'),
        ];
    }
}
