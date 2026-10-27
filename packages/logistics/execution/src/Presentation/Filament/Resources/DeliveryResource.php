<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources;

use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordNodeReception\RecordNodeReception;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Command\RecordUnloadScan\RecordUnloadScan;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource\Pages\ListDeliveries;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource\Pages\ViewDelivery;
use Logistics\Execution\Presentation\Filament\Support\UnitNames;
use Logistics\Execution\Presentation\Filament\Support\UserNames;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Admin de las entregas en ejecución: observación legible + flujos operativos reales.
 *
 * Las entregas y sus escaneos son hechos de terreno inmutables (append-only). El admin NO los edita
 * como filas: cada acción operativa (preparación, carga, descarga, recepción en nodo, prueba de
 * entrega, fallo) despacha el Command correspondiente por el CommandBus, de modo que se respetan las
 * invariantes del agregado y el outbox transaccional (Presentation → Application, nunca Infrastructure
 * ni Domain directo).
 *
 * Datos legibles: el pedido se muestra por su referencia externa (shipment.external_id) y el vehículo
 * por su placa (vehicle.plate), nunca por UUID. Todo el texto visible va en inglés (regla CLAUDE.md #5).
 *
 * @extends resource<DeliveryRecord>
 */
final class DeliveryResource extends Resource
{
    use ResolvesUserRoles;

    /**
     * Entregas de última milla: las gestiona la oficina (admin/supervisor) y las ejecuta el chofer
     * desde su ruta. Los demás roles no ven esta sección.
     *
     * Sobrescribir canAccess() basta: Filament lo usa para ocultar del menú y bloquear la URL (403).
     */
    public static function canAccess(): bool
    {
        return self::authUserHasAnyRoleValue('admin', 'supervisor', 'chofer');
    }

    protected static ?string $model = DeliveryRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'ds-truck';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Deliveries');
    }

    public static function getModelLabel(): string
    {
        return __('delivery');
    }

    public static function getPluralModelLabel(): string
    {
        return __('deliveries');
    }

    /**
     * El alta/edición como fila está deshabilitada: las entregas nacen y avanzan por escaneos de
     * terreno despachados como comandos, no por formularios CRUD.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Título humano del registro: la referencia del pedido, nunca el UUID de la entrega ni del pedido.
     * Si el pedido no tiene referencia externa legible, no se muestra ningún identificador crudo.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof DeliveryRecord) {
            return null;
        }

        $order = $record->shipment->external_id ?? null;

        return ($order !== null && $order !== '') ? $order : null;
    }

    /**
     * Eager-load del pedido y el vehículo para resolver las etiquetas legibles sin N+1.
     *
     * @return Builder<DeliveryRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['shipment', 'vehicle']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shipment.external_id')
                    ->label(__('Order'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('vehicle.plate')
                    ->label(__('Vehicle'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                    ->color(static fn (string $state): string => self::statusColor($state)),
                TextColumn::make('failure_reason')
                    ->label(__('Failure reason'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : self::failureReasonLabel($state))
                    ->placeholder('—'),
                TextColumn::make('pod_captured_at')
                    ->label(__('Delivered at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Opened at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
                SelectFilter::make('failure_reason')
                    ->label(__('Failure reason'))
                    ->native(false)
                    ->options(self::failureReasonOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    self::loadScanAction(),
                    self::unloadScanAction(),
                    self::nodeReceptionAction(),
                    self::proofOfDeliveryAction(),
                    self::failedDeliveryAction(),
                ])
                    ->label(__('Record scan'))
                    ->icon('ds-qr-code')
                    ->button(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shipment.external_id')->label(__('Order'))->placeholder('—'),
                TextEntry::make('vehicle.plate')->label(__('Vehicle'))->placeholder('—'),
                TextEntry::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                    ->color(static fn (string $state): string => self::statusColor($state)),
                TextEntry::make('pod_confirmation')->label(__('Proof of delivery'))->placeholder('—'),
                // Foto del POD como IMAGEN visible (no solo la URL). Es una URL externa (CDN): no se
                // verifica en disco. Si la imagen no existe o fue eliminada, el onerror oculta el <img>
                // y muestra un mensaje; si no hay URL, el placeholder evita dejar el campo en blanco.
                ImageEntry::make('pod_photo_url')
                    ->label(__('Photo'))
                    ->checkFileExistence(false)
                    ->height(160)
                    // Sin URL → placeholder; URL que responde 404 → onerror la cambia por el placeholder.
                    // Nunca una imagen rota ni un hueco en blanco.
                    ->defaultImageUrl(ImageFallback::placeholder('box'))
                    ->extraImgAttributes(ImageFallback::onError('box', [
                        'class' => 'rounded-md object-contain bg-gray-50',
                    ])),
                TextEntry::make('pod_captured_at')->label(__('Delivered at'))->dateTime()->placeholder('—'),
                TextEntry::make('failure_reason')
                    ->label(__('Failure reason'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : self::failureReasonLabel($state))
                    ->placeholder('—'),
                RepeatableEntry::make('scans')
                    ->label(__('Scans (field facts, append-only)'))
                    ->schema([
                        TextEntry::make('type')
                            ->label(__('Type'))
                            ->badge()
                            ->formatStateUsing(static fn (string $state): string => self::scanTypeLabel($state)),
                        TextEntry::make('unit')
                            ->label(__('Unit'))
                            ->state(static fn (ScanEventRecord $scan): string => self::scanUnitLabel($scan)),
                        TextEntry::make('occurred_at')->label(__('Occurred at'))->dateTime(),
                        // user_id se muestra como el NOMBRE del operador (users.name), nunca el id crudo.
                        TextEntry::make('user_id')
                            ->label(__('User'))
                            ->formatStateUsing(static fn (?string $state): string => UserNames::label($state)),
                        TextEntry::make('lat')->label(__('Lat')),
                        TextEntry::make('lng')->label(__('Lng')),
                    ])
                    ->columns(4),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDeliveries::route('/'),
            'view' => ViewDelivery::route('/{record}'),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Flujos transaccionales: cada acción mapea su formulario a un Command y lo despacha por el bus.
    // ---------------------------------------------------------------------------------------------

    /**
     * Preparación (EAN → etiqueta → carril): abre la entrega del pedido con el vehículo indicado.
     *
     * Es acción de encabezado (lista) porque es el primer escaneo del ciclo: todavía no hay fila de
     * entrega sobre la que operar. Despacha RecordPreparationScan.
     */
    public static function preparationScanAction(): Action
    {
        return Action::make('recordPreparationScan')
            ->label(__('Record preparation scan'))
            ->icon('ds-qr-code')
            ->modalHeading(__('Record preparation scan'))
            ->modalDescription(__('Scan a unit at preparation. Opens the delivery for the order if it does not exist yet.'))
            ->modalSubmitActionLabel(__('Record scan'))
            ->schema([
                Select::make('shipment_id')
                    ->label(__('Order'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => self::searchShipments($search))
                    ->getOptionLabelUsing(static fn (string $value): ?string => self::shipmentLabel($value))
                    ->live(),
                Select::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => self::searchVehicles($search))
                    ->getOptionLabelUsing(static fn (string $value): ?string => self::vehicleLabel($value)),
                self::unitSelect(),
                ...self::contextFields(),
            ])
            ->action(function (array $data): void {
                self::dispatch(new RecordPreparationScan(
                    shipmentId: (string) $data['shipment_id'],
                    vehicleId: (string) $data['vehicle_id'],
                    unitId: (string) $data['unit_id'],
                    lat: (float) $data['lat'],
                    lng: (float) $data['lng'],
                    deviceId: (string) $data['device_id'],
                    userId: (string) $data['user_id'],
                    occurredAt: self::iso($data['occurred_at']),
                    idempotencyKey: (string) Str::uuid(),
                ), __('Preparation scan recorded'));
            });
    }

    /**
     * Carga validada del producto al vehículo. Row action: el pedido viene de la fila. Despacha RecordLoadScan.
     */
    private static function loadScanAction(): Action
    {
        return Action::make('recordLoadScan')
            ->label(__('Load scan'))
            ->icon('ds-arrow-up-tray')
            ->modalHeading(__('Record load scan'))
            ->modalDescription(__('Confirm a unit loaded onto the vehicle.'))
            ->modalSubmitActionLabel(__('Record scan'))
            ->schema(fn (DeliveryRecord $record): array => [
                self::unitSelectFor($record),
                ...self::contextFields(),
            ])
            ->action(function (array $data, DeliveryRecord $record): void {
                self::dispatch(new RecordLoadScan(
                    shipmentId: $record->shipment_id,
                    unitId: (string) $data['unit_id'],
                    lat: (float) $data['lat'],
                    lng: (float) $data['lng'],
                    deviceId: (string) $data['device_id'],
                    userId: (string) $data['user_id'],
                    occurredAt: self::iso($data['occurred_at']),
                    idempotencyKey: (string) Str::uuid(),
                ), __('Load scan recorded'));
            });
    }

    /**
     * Descarga guiada del producto en una parada. Row action. Despacha RecordUnloadScan.
     */
    private static function unloadScanAction(): Action
    {
        return Action::make('recordUnloadScan')
            ->label(__('Unload scan'))
            ->icon('ds-arrow-down-tray')
            ->modalHeading(__('Record unload scan'))
            ->modalDescription(__('Confirm a unit unloaded at a stop (node).'))
            ->modalSubmitActionLabel(__('Record scan'))
            ->schema(fn (DeliveryRecord $record): array => [
                self::nodeSelect(),
                self::unitSelectFor($record),
                ...self::contextFields(),
            ])
            ->action(function (array $data, DeliveryRecord $record): void {
                self::dispatch(new RecordUnloadScan(
                    shipmentId: $record->shipment_id,
                    nodeId: (string) $data['node_id'],
                    unitId: (string) $data['unit_id'],
                    lat: (float) $data['lat'],
                    lng: (float) $data['lng'],
                    deviceId: (string) $data['device_id'],
                    userId: (string) $data['user_id'],
                    occurredAt: self::iso($data['occurred_at']),
                    idempotencyKey: (string) Str::uuid(),
                ), __('Unload scan recorded'));
            });
    }

    /**
     * Recepción del producto en un nodo logístico. Row action. Despacha RecordNodeReception.
     */
    private static function nodeReceptionAction(): Action
    {
        return Action::make('recordNodeReception')
            ->label(__('Node reception'))
            ->icon('ds-inbox-arrow-down')
            ->modalHeading(__('Record node reception'))
            ->modalDescription(__('Confirm a unit received at a logistics node.'))
            ->modalSubmitActionLabel(__('Record scan'))
            ->schema(fn (DeliveryRecord $record): array => [
                self::nodeSelect(),
                self::unitSelectFor($record),
                ...self::contextFields(),
            ])
            ->action(function (array $data, DeliveryRecord $record): void {
                self::dispatch(new RecordNodeReception(
                    shipmentId: $record->shipment_id,
                    nodeId: (string) $data['node_id'],
                    unitId: (string) $data['unit_id'],
                    lat: (float) $data['lat'],
                    lng: (float) $data['lng'],
                    deviceId: (string) $data['device_id'],
                    userId: (string) $data['user_id'],
                    occurredAt: self::iso($data['occurred_at']),
                    idempotencyKey: (string) Str::uuid(),
                ), __('Node reception recorded'));
            });
    }

    /**
     * Prueba de entrega (QR del cliente o firma + foto opcional): cierra la entrega. Row action.
     * Despacha RecordProofOfDelivery.
     */
    private static function proofOfDeliveryAction(): Action
    {
        return Action::make('recordProofOfDelivery')
            ->label(__('Proof of delivery'))
            ->icon('ds-check-badge')
            ->extraAttributes(['class' => 'ds-icon-pop-hover'])
            ->color('success')
            ->modalHeading(__('Record proof of delivery'))
            ->modalDescription(__('Confirm the delivery with the customer confirmation (QR or signature) and an optional photo.'))
            ->modalSubmitActionLabel(__('Confirm delivery'))
            ->schema(fn (DeliveryRecord $record): array => [
                self::unitSelectFor($record),
                TextInput::make('confirmation')
                    ->label(__('Customer confirmation'))
                    ->helperText(__('Customer QR code or signature reference.'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('photo_url')
                    ->label(__('Photo URL'))
                    ->url()
                    ->maxLength(2048),
                ...self::contextFields(),
            ])
            ->action(function (array $data, DeliveryRecord $record): void {
                $photo = isset($data['photo_url']) && $data['photo_url'] !== '' ? (string) $data['photo_url'] : null;

                self::dispatch(new RecordProofOfDelivery(
                    shipmentId: $record->shipment_id,
                    unitId: (string) $data['unit_id'],
                    confirmation: (string) $data['confirmation'],
                    photoUrl: $photo,
                    photoPath: null,
                    lat: (float) $data['lat'],
                    lng: (float) $data['lng'],
                    deviceId: (string) $data['device_id'],
                    userId: (string) $data['user_id'],
                    occurredAt: self::iso($data['occurred_at']),
                    idempotencyKey: (string) Str::uuid(),
                ), __('Proof of delivery recorded'));
            });
    }

    /**
     * Intento de entrega fallido con motivo tipificado: cierra la entrega como fallida. Row action.
     * Despacha RecordFailedDelivery.
     */
    private static function failedDeliveryAction(): Action
    {
        return Action::make('recordFailedDelivery')
            ->label(__('Failed delivery'))
            ->icon('ds-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Record failed delivery'))
            ->modalDescription(__('Close the delivery as failed with a typified reason.'))
            ->modalSubmitActionLabel(__('Record failure'))
            ->schema(fn (DeliveryRecord $record): array => [
                self::unitSelectFor($record),
                Select::make('reason')
                    ->label(__('Failure reason'))
                    ->required()
                    ->native(false)
                    ->options(self::failureReasonOptions()),
                ...self::contextFields(),
            ])
            ->action(function (array $data, DeliveryRecord $record): void {
                self::dispatch(new RecordFailedDelivery(
                    shipmentId: $record->shipment_id,
                    unitId: (string) $data['unit_id'],
                    reason: (string) $data['reason'],
                    lat: (float) $data['lat'],
                    lng: (float) $data['lng'],
                    deviceId: (string) $data['device_id'],
                    userId: (string) $data['user_id'],
                    occurredAt: self::iso($data['occurred_at']),
                    idempotencyKey: (string) Str::uuid(),
                ), __('Failed delivery recorded'));
            });
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers de formulario y despacho.
    // ---------------------------------------------------------------------------------------------

    /**
     * Despacha un comando por el CommandBus y notifica el resultado. Centraliza el manejo de error
     * para todos los flujos operativos.
     */
    private static function dispatch(Command $command, string $successTitle): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $bus->dispatch($command);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Could not record scan'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Campos comunes de todo escaneo: GPS, dispositivo, usuario y hora del hecho.
     *
     * @return array<int, Component>
     */
    private static function contextFields(): array
    {
        return [
            TextInput::make('lat')
                ->label(__('Latitude'))
                ->numeric()
                ->required()
                ->minValue(-90)
                ->maxValue(90),
            TextInput::make('lng')
                ->label(__('Longitude'))
                ->numeric()
                ->required()
                ->minValue(-180)
                ->maxValue(180),
            TextInput::make('device_id')
                ->label(__('Device'))
                ->required()
                ->default('admin-panel')
                ->maxLength(255),
            TextInput::make('user_id')
                ->label(__('User'))
                ->required()
                ->default(static fn (): string => (string) (auth()->user()?->getAuthIdentifier() ?? 'admin'))
                ->maxLength(255),
            DateTimePicker::make('occurred_at')
                ->label(__('Occurred at'))
                ->seconds(false)
                ->required()
                ->default(static fn (): CarbonInterface => now()),
        ];
    }

    /**
     * Select de producto sin pedido fijo (preparación): busca productos por referencia del pedido.
     */
    private static function unitSelect(): Select
    {
        return Select::make('unit_id')
            ->label(__('Unit'))
            ->required()
            ->native(false)
            ->searchable()
            ->getSearchResultsUsing(static fn (string $search): array => self::searchUnits($search, null))
            ->getOptionLabelUsing(static fn (string $value): ?string => self::unitLabel($value));
    }

    /**
     * Select de producto acotado a los productos del pedido de la entrega (carga/descarga/entrega/fallo).
     */
    private static function unitSelectFor(DeliveryRecord $record): Select
    {
        $units = PhysicalUnitRecord::query()
            ->where('shipment_id', $record->shipment_id)
            ->with(['shipment', 'line'])
            ->get()
            ->mapWithKeys(static fn (PhysicalUnitRecord $unit): array => [$unit->id => self::unitLabelFor($unit)])
            ->all();

        return Select::make('unit_id')
            ->label(__('Unit'))
            ->required()
            ->native(false)
            ->searchable()
            ->options($units);
    }

    /**
     * Select de nodo (parada) buscable por código/nombre reales de la red logística.
     */
    private static function nodeSelect(): Select
    {
        return Select::make('node_id')
            ->label(__('Stop (node)'))
            ->required()
            ->native(false)
            ->searchable()
            ->getSearchResultsUsing(static fn (string $search): array => self::searchNodes($search))
            ->getOptionLabelUsing(static fn (string $value): ?string => self::nodeLabel($value));
    }

    /**
     * @return array<string, string>
     */
    private static function searchShipments(string $search): array
    {
        return ShipmentRecord::query()
            ->where('external_id', 'ilike', "%{$search}%")
            ->orWhere('customer_name', 'ilike', "%{$search}%")
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn ($s): array => [$s->id => "{$s->external_id} — {$s->customer_name}"])
            ->all();
    }

    private static function shipmentLabel(string $id): ?string
    {
        $s = ShipmentRecord::query()->find($id);

        return $s === null ? null : "{$s->external_id} — {$s->customer_name}";
    }

    /**
     * @return array<string, string>
     */
    private static function searchVehicles(string $search): array
    {
        return VehicleModel::query()
            ->where('plate', 'ilike', "%{$search}%")
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn ($v): array => [$v->id => $v->plate])
            ->all();
    }

    private static function vehicleLabel(string $id): ?string
    {
        return VehicleModel::query()->find($id)?->plate;
    }

    /**
     * @return array<string, string>
     */
    private static function searchNodes(string $search): array
    {
        return NodeRecord::query()
            ->where('code', 'ilike', "%{$search}%")
            ->orWhere('name', 'ilike', "%{$search}%")
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn ($n): array => [$n->id => "{$n->code} — {$n->name}"])
            ->all();
    }

    private static function nodeLabel(string $id): ?string
    {
        $n = NodeRecord::query()->find($id);

        return $n === null ? null : "{$n->code} — {$n->name}";
    }

    /**
     * @return array<string, string>
     */
    private static function searchUnits(string $search, ?string $shipmentId): array
    {
        return PhysicalUnitRecord::query()
            ->when($shipmentId !== null, static fn (Builder $q): Builder => $q->where('shipment_id', $shipmentId))
            ->whereHas('shipment', static fn (Builder $q): Builder => $q->where('external_id', 'ilike', "%{$search}%"))
            ->with(['shipment', 'line'])
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn (PhysicalUnitRecord $u): array => [$u->id => self::unitLabelFor($u)])
            ->all();
    }

    private static function unitLabel(string $id): ?string
    {
        $u = PhysicalUnitRecord::query()->with(['shipment', 'line'])->find($id);

        return $u === null ? null : self::unitLabelFor($u);
    }

    /**
     * Etiqueta humana del producto de un escaneo para el infolist.
     *
     * `execution_scan_events.unit_id` puede ser el UUID del producto (escaneos registrados desde el panel)
     * o el código legible de la etiqueta escaneada en terreno. Se resuelve con UnitNames a una etiqueta
     * legible (pedido + índice de producto/pieza) y NUNCA se expone el UUID crudo.
     */
    private static function scanUnitLabel(ScanEventRecord $scan): string
    {
        return UnitNames::label($scan->unit_id);
    }

    /**
     * Etiqueta humana del producto: pedido + nombre del producto y su SKU (+ pieza física N de M),
     * nunca el UUID ni vacío. Si falta el nombre se usa el SKU y, si no hay ninguno, "Producto".
     */
    private static function unitLabelFor(PhysicalUnitRecord $unit): string
    {
        $order = $unit->shipment->external_id ?? $unit->shipment_id;
        $product = self::unitProductLabel($unit);
        $piece = self::unitPieceSuffix($unit);

        return trim("{$order} — {$product} {$piece}");
    }

    /**
     * Producto de una unidad física como "{nombre} · {SKU}", con fallback a SKU y a "Producto".
     */
    private static function unitProductLabel(PhysicalUnitRecord $unit): string
    {
        $name = is_string($unit->line?->product_name) ? trim($unit->line->product_name) : '';
        $sku = is_string($unit->line?->sku) ? trim($unit->line->sku) : '';

        if ($name !== '' && $sku !== '') {
            return "{$name} · {$sku}";
        }

        return match (true) {
            $name !== '' => $name,
            $sku !== '' => $sku,
            default => 'Producto',
        };
    }

    /**
     * Sufijo "(N de M)" de la pieza física (o de la unidad N de M del pedido); vacío si no aplica.
     */
    private static function unitPieceSuffix(PhysicalUnitRecord $unit): string
    {
        if ($unit->piece_total > 1) {
            return "({$unit->piece_index} de {$unit->piece_total})";
        }
        if ($unit->unit_total > 1) {
            return "({$unit->unit_index} de {$unit->unit_total})";
        }

        return '';
    }

    /**
     * Normaliza la hora del formulario a ISO-8601 para el Command.
     */
    private static function iso(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * Etiqueta en inglés del estado de entrega.
     */
    private static function statusLabel(string $value): string
    {
        return self::statusOptions()[$value] ?? $value;
    }

    /**
     * Color semántico del badge de estado.
     */
    private static function statusColor(string $value): string
    {
        return match ($value) {
            DeliveryStatus::Delivered->value => 'success',
            DeliveryStatus::Failed->value => 'danger',
            DeliveryStatus::InTransit->value => 'info',
            DeliveryStatus::Loaded->value, DeliveryStatus::Prepared->value => 'warning',
            default => 'gray',
        };
    }

    /**
     * Etiqueta en inglés del motivo de fallo.
     */
    private static function failureReasonLabel(string $value): string
    {
        return self::failureReasonOptions()[$value] ?? $value;
    }

    /**
     * Etiqueta en inglés del tipo de escaneo (para el infolist de escaneos).
     */
    private static function scanTypeLabel(string $value): string
    {
        return match ($value) {
            'PREPARATION' => __('Preparation'),
            'LOAD' => __('Load'),
            'UNLOAD' => __('Unload'),
            'PROOF_OF_DELIVERY' => __('Proof of delivery'),
            'NODE_RECEPTION' => __('Node reception'),
            default => $value,
        };
    }

    /**
     * Opciones del estado de entrega como value → label en inglés.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            DeliveryStatus::Pending->value => __('Pending'),
            DeliveryStatus::Prepared->value => __('Prepared'),
            DeliveryStatus::Loaded->value => __('Loaded'),
            DeliveryStatus::InTransit->value => __('In transit'),
            DeliveryStatus::Delivered->value => __('Delivered'),
            DeliveryStatus::Failed->value => __('Failed'),
        ];
    }

    /**
     * Opciones del motivo de fallo como value → label en inglés.
     *
     * @return array<string, string>
     */
    private static function failureReasonOptions(): array
    {
        return [
            FailureReason::CustomerAbsent->value => __('Customer absent'),
            FailureReason::WrongAddress->value => __('Wrong address'),
            FailureReason::Rejected->value => __('Rejected'),
            FailureReason::Damaged->value => __('Damaged'),
            FailureReason::Other->value => __('Other'),
        ];
    }
}
