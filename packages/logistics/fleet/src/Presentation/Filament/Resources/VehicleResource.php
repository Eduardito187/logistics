<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\EditVehicle;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\ListVehicles;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\ViewVehicle;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Command\CommandBus;

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
                ->default([]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plate')
                    ->label(__('Plate'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('capacity_kg')
                    ->label(__('Capacity (kg)'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('capacity_m3')
                    ->label(__('Capacity (m³)'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
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
                    ->formatStateUsing(static fn (string $state): string => __(VehicleStatus::from($state)->label())),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                self::changeStatusAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('plate');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('plate')->label(__('Plate'))->copyable(),
            TextEntry::make('capacity_kg')->label(__('Capacity (kg)'))->numeric(),
            TextEntry::make('capacity_m3')->label(__('Capacity (m³)'))->numeric(decimalPlaces: 3),
            TextEntry::make('homeBaseNode.name')
                ->label(__('Home base'))
                ->placeholder('—'),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->formatStateUsing(static fn (string $state): string => __(VehicleStatus::from($state)->label())),
            RepeatableEntry::make('documents')
                ->label(__('Documents'))
                ->schema([
                    TextEntry::make('type')->label(__('Document type')),
                    TextEntry::make('expires_on')->label(__('Expires on'))->date('Y-m-d'),
                ])
                ->columns(2),
        ]);
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
}
