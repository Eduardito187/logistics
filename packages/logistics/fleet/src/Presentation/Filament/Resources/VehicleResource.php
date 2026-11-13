<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use App\Filament\Support\TableCsvExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Application\Command\ClearVehicleFitness\ClearVehicleFitness;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\EditVehicle;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\ListVehicles;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\ViewVehicle;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shared\Presentation\Filament\Concerns\PlainNavigationLabel;
use Logistics\Shared\Presentation\Filament\ImageFallback;

/**
 * Resource de administración de vehículos de la flota.
 *
 * Filament necesita un modelo Eloquent para el lado de LECTURA (tabla y formularios); por eso apunta
 * a VehicleModel. Las ESCRITURAS no tocan el modelo directo: las páginas Create/Edit despachan los
 * comandos del dominio (RegisterVehicle / ChangeVehicleStatus) por el CommandBus, respetando CQRS.
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md). El estado base del vehículo no se puede
 * editar una vez creado: al editar solo se cambia el estado operativo (único comando de modificación).
 */
final class VehicleResource extends Resource
{
    use OfficeOnly;
    use PlainNavigationLabel;

    protected static ?string $model = VehicleModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Vehicle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Vehicles');
    }

    // Título legible del registro (listas de selección, breadcrumbs, búsqueda global): la placa, no el UUID.
    protected static ?string $recordTitleAttribute = 'plate';

    /**
     * Eager-load del nodo base para que la columna `homeBaseNode.name` no dispare N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('homeBaseNode');
    }

    public static function form(Schema $schema): Schema
    {
        // En edición solo el estado es mutable: el resto queda deshabilitado (no hay comando que los
        // cambie). En creación todos los campos están activos y alimentan RegisterVehicle.
        $lockedOnEdit = static fn (string $operation): bool => $operation === 'edit';

        return $schema->components([
            Section::make(__('Registration'))
                ->schema([
                    TextInput::make('plate')
                        ->label(__('Plate'))
                        ->required()
                        ->maxLength(20)
                        ->disabled($lockedOnEdit),

                    TextInput::make('capacity_kg')
                        ->label(__('Capacity (kg)'))
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->disabled($lockedOnEdit),

                    TextInput::make('capacity_m3')
                        ->label(__('Capacity (m³)'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001)
                        ->required()
                        ->disabled($lockedOnEdit),

                    Select::make('home_base_node_id')
                        ->label(__('Home base'))
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->disabled($lockedOnEdit)
                        // Datos reales desde la red de nodos: búsqueda por nombre o código.
                        ->getSearchResultsUsing(static fn (string $search): array => NodeRecord::query()
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('code', 'ilike', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(static fn (string $value): ?string => NodeRecord::query()
                            ->whereKey($value)
                            ->value('name')),

                    Select::make('status')
                        ->label(__('Status'))
                        ->required()
                        ->native(false)
                        ->options(self::statusOptions()),

                    Repeater::make('documents')
                        ->label(__('Documents'))
                        ->disabled($lockedOnEdit)
                        ->schema([
                            TextInput::make('type')
                                ->label(__('Document type'))
                                ->required()
                                ->maxLength(50),
                            DatePicker::make('expires_on')
                                ->label(__('Expires on'))
                                ->native(false)
                                ->required()
                                ->format('Y-m-d'),
                        ])
                        ->columns(2)
                        ->default([])
                        ->columnSpanFull(),
                ])
                ->columns(2),

            // Ficha técnica y fotos: solo se gestionan en edición (el alta solo registra placa/capacidad).
            Section::make(__('Vehicle details'))
                ->visibleOn('edit')
                ->schema([
                    TextInput::make('make')
                        ->label(__('Make'))
                        ->maxLength(80),

                    TextInput::make('model')
                        ->label(__('Model'))
                        ->maxLength(80),

                    TextInput::make('model_year')
                        ->label(__('Model year'))
                        ->numeric()
                        ->minValue(1950)
                        ->maxValue((int) date('Y') + 1),

                    TextInput::make('vin')
                        ->label(__('VIN'))
                        ->maxLength(40),

                    TextInput::make('engine_number')
                        ->label(__('Engine number'))
                        ->maxLength(40),

                    TextInput::make('color')
                        ->label(__('Color'))
                        ->maxLength(40),

                    FileUpload::make('photo_front')
                        ->label(__('Front photo'))
                        ->disk(self::documentsDisk())
                        ->directory('fleet-vehicle-photos')
                        ->visibility('private')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png']),

                    FileUpload::make('photo_back')
                        ->label(__('Back photo'))
                        ->disk(self::documentsDisk())
                        ->directory('fleet-vehicle-photos')
                        ->visibility('private')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png']),

                    FileUpload::make('photo_left')
                        ->label(__('Left photo'))
                        ->disk(self::documentsDisk())
                        ->directory('fleet-vehicle-photos')
                        ->visibility('private')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png']),

                    FileUpload::make('photo_right')
                        ->label(__('Right photo'))
                        ->disk(self::documentsDisk())
                        ->directory('fleet-vehicle-photos')
                        ->visibility('private')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png']),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Foto frontal cuadrada pequeña; si falta o el disco no es alcanzable, pinta el placeholder.
                ImageColumn::make('photo_front')
                    ->label(__('Photo'))
                    ->square()
                    ->imageSize(40)
                    ->checkFileExistence(false)
                    ->getStateUsing(static fn (VehicleModel $record): ?string => ImageFallback::diskUrl(self::documentsDisk(), $record->photo_front))
                    ->defaultImageUrl(ImageFallback::placeholder('box'))
                    ->extraImgAttributes(ImageFallback::onError('box')),
                TextColumn::make('plate')
                    ->label(__('Plate'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('make')
                    ->label(__('Make'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model')
                    ->label(__('Model'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model_year')
                    ->label(__('Model year'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('capacity_kg')
                    ->label(__('Capacity (kg)'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('capacity_m3')
                    ->label(__('Capacity (m³)'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->toggleable(),
                // Nombre legible del nodo base vía la relación Eloquent (eager-cargada), no el UUID.
                TextColumn::make('homeBaseNode.name')
                    ->label(__('Home base'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        VehicleStatus::Available->value => 'success',
                        VehicleStatus::InService->value => 'info',
                        VehicleStatus::Maintenance->value => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (string $state): string => __(VehicleStatus::fromLoose($state)->label())),
                // Aptitud para despachar (#27): no apto por checklist pre-salida con falla crítica.
                TextColumn::make('unfit_since')
                    ->label(__('Dispatch fitness'))
                    ->badge()
                    ->getStateUsing(static fn (VehicleModel $record): string => $record->unfit_since === null ? 'fit' : 'unfit')
                    ->color(static fn (string $state): string => $state === 'fit' ? 'success' : 'danger')
                    ->formatStateUsing(static fn (string $state, VehicleModel $record): string => $state === 'fit'
                        ? __('Fit')
                        : __('Unfit since :date', ['date' => (string) $record->unfit_since]))
                    ->tooltip(static fn (VehicleModel $record): ?string => $record->unfit_reason)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
            ])
            // Click en la fila → ficha 360 del vehículo (el hub ES la entrada, no una tabla cruda). Los
            // botones de la fila (cambiar estado, editar) siguen disponibles.
            ->recordUrl(static fn (VehicleModel $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                self::clearFitnessAction(),
                self::changeStatusAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::changeStatusBulkAction(),
                    TableCsvExport::bulkAction(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('plate');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Registration'))
                ->schema([
                    TextEntry::make('plate')->label(__('Plate'))->copyable(),
                    TextEntry::make('capacity_kg')->label(__('Capacity (kg)'))->numeric(),
                    TextEntry::make('capacity_m3')->label(__('Capacity (m³)'))->numeric(decimalPlaces: 3),
                    TextEntry::make('homeBaseNode.name')
                        ->label(__('Home base'))
                        ->placeholder('—'),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(VehicleStatus::fromLoose($state)->label())),
                ])
                ->columns(2),

            Section::make(__('Vehicle details'))
                ->schema([
                    TextEntry::make('make')->label(__('Make'))->placeholder('—'),
                    TextEntry::make('model')->label(__('Model'))->placeholder('—'),
                    TextEntry::make('model_year')->label(__('Model year'))->placeholder('—'),
                    TextEntry::make('vin')->label(__('VIN'))->placeholder('—')->copyable(),
                    TextEntry::make('engine_number')->label(__('Engine number'))->placeholder('—')->copyable(),
                    TextEntry::make('color')->label(__('Color'))->placeholder('—'),
                ])
                ->columns(2),

            Section::make(__('Photos'))
                ->schema([
                    self::photoEntry('photo_front', __('Front photo')),
                    self::photoEntry('photo_back', __('Back photo')),
                    self::photoEntry('photo_left', __('Left photo')),
                    self::photoEntry('photo_right', __('Right photo')),
                ])
                ->columns(4),

            RepeatableEntry::make('documents')
                ->label(__('Embedded documents'))
                ->placeholder('—')
                ->schema([
                    TextEntry::make('type')->label(__('Document type')),
                    TextEntry::make('expires_on')->label(__('Expires on'))->date('Y-m-d'),
                ])
                ->columns(2),

            // Documentos administrables del vehículo (SOAT, inspección técnica, etc.) con estado y archivo.
            OwnerDocuments::infolistSection('vehicle'),
        ]);
    }

    /**
     * Entrada de imagen para una foto del vehículo guardada en disco, con placeholder y fallback de error
     * (nunca se ve rota ni deja un hueco).
     */
    private static function photoEntry(string $field, string $label): ImageEntry
    {
        return ImageEntry::make($field)
            ->label($label)
            ->square()
            ->imageSize(120)
            ->checkFileExistence(false)
            ->getStateUsing(static fn (VehicleModel $record): ?string => ImageFallback::diskUrl(self::documentsDisk(), $record->{$field}))
            ->defaultImageUrl(ImageFallback::placeholder('box'))
            ->extraImgAttributes(ImageFallback::onError('box'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/create'),
            'view' => ViewVehicle::route('/{record}'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }

    /**
     * Flujo transaccional: cambiar el estado operativo del vehículo.
     *
     * No escribe el Record directo: despacha ChangeVehicleStatus por el CommandBus, de modo que el
     * dominio valide la transición y el outbox quede dentro de la misma transacción. Pide confirmación
     * y notifica éxito o el error de negocio (FleetException).
     */
    private static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('Change status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->modalHeading(__('Change vehicle status'))
            ->modalSubmitActionLabel(__('Change status'))
            ->fillForm(static fn (VehicleModel $record): array => ['status' => $record->status])
            ->schema([
                Select::make('status')
                    ->label(__('New status'))
                    ->required()
                    ->native(false)
                    ->options(self::statusOptions()),
            ])
            ->action(static function (VehicleModel $record, array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new ChangeVehicleStatus(
                        vehicleId: (string) $record->getKey(),
                        status: (string) $data['status'],
                    ));

                    Notification::make()
                        ->title(__('Vehicle status changed'))
                        ->success()
                        ->send();
                } catch (FleetException $e) {
                    Notification::make()
                        ->title(__('Could not change status'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Habilitar un vehículo NO APTO tras la reparación (#27). Solo visible si está bloqueado.
     *
     * No escribe el Record: despacha ClearVehicleFitness por el CommandBus con el autor de la sesión; el
     * dominio exige la nota, se deja constancia en la bitácora de aptitud y se publica
     * `fleet.vehicle_cleared` al outbox. La acción es pública para reusarla en la ficha 360 del vehículo.
     */
    public static function clearFitnessAction(): Action
    {
        return Action::make('clearFitness')
            ->label(__('Clear for dispatch'))
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->visible(static fn (VehicleModel $record): bool => $record->unfit_since !== null)
            ->modalHeading(__('Clear vehicle for dispatch'))
            ->modalDescription(static fn (VehicleModel $record): string => __('Unfit since :date: :reason', [
                'date' => (string) $record->unfit_since,
                'reason' => (string) $record->unfit_reason,
            ]))
            ->modalSubmitActionLabel(__('Clear for dispatch'))
            ->schema([
                Textarea::make('note')
                    ->label(__('Repair note'))
                    ->helperText(__('Describe what was repaired. This is recorded in the audit log.'))
                    ->required()
                    ->minLength(3)
                    ->maxLength(1000),
            ])
            ->action(static function (VehicleModel $record, array $data): void {
                $user = Auth::user();
                $actorId = $user !== null ? (string) $user->getAuthIdentifier() : null;
                $actorLabel = 'System';
                if ($user instanceof Model) {
                    $name = $user->getAttribute('name');
                    $email = $user->getAttribute('email');
                    $actorLabel = is_string($name) && trim($name) !== ''
                        ? $name
                        : (is_string($email) && $email !== '' ? $email : ($actorId ?? 'System'));
                }

                try {
                    app(CommandBus::class)->dispatch(new ClearVehicleFitness(
                        vehicleId: (string) $record->getKey(),
                        note: (string) ($data['note'] ?? ''),
                        clearedById: $actorId,
                        clearedByLabel: $actorLabel,
                    ));

                    Notification::make()
                        ->title(__('Vehicle cleared for dispatch'))
                        ->success()
                        ->send();
                } catch (FleetException $e) {
                    Notification::make()
                        ->title(__('Could not clear the vehicle'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Acción masiva: cambia el estado de los vehículos seleccionados. No escribe el Record directo;
     * despacha un ChangeVehicleStatus por vehículo por el CommandBus (CQRS, invariantes + outbox).
     * Tolerante: cuenta éxitos y fallos y reporta el resumen, sin frenar por un vehículo malo.
     */
    private static function changeStatusBulkAction(): BulkAction
    {
        return BulkAction::make('changeStatusBulk')
            ->label(__('Change status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Change vehicle status'))
            ->modalSubmitActionLabel(__('Change status'))
            ->deselectRecordsAfterCompletion()
            ->accessSelectedRecords()
            ->schema([
                Select::make('status')
                    ->label(__('New status'))
                    ->required()
                    ->native(false)
                    ->options(self::statusOptions()),
            ])
            ->action(static function (Collection $records, array $data): void {
                $status = (string) $data['status'];
                $bus = app(CommandBus::class);
                $changed = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (!$record instanceof VehicleModel) {
                        continue;
                    }

                    try {
                        $bus->dispatch(new ChangeVehicleStatus(
                            vehicleId: (string) $record->getKey(),
                            status: $status,
                        ));
                        $changed++;
                    } catch (FleetException) {
                        $failed++;
                    }
                }

                $notification = Notification::make()
                    ->title(__('Vehicle status changed'))
                    ->body(__(':changed changed, :failed failed.', ['changed' => $changed, 'failed' => $failed]));

                ($failed > 0 ? $notification->warning() : $notification->success())->send();
            });
    }

    /**
     * Opciones del estado del vehículo con etiqueta en inglés (desde el enum del dominio).
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];
        foreach (VehicleStatus::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Disco donde se guardan fotos y archivos de flota (configurable por env).
     */
    private static function documentsDisk(): string
    {
        $disk = config('fleet.documents_disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }
}
