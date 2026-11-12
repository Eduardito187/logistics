<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament;

use App\Filament\Support\RequestScopedQuery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Application\Command\RemoveVehicleRestrictions\RemoveVehicleRestrictions;
use Logistics\Planning\Application\Command\SetVehicleRestrictions\SetVehicleRestrictions;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\ShipmentRequirementRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\VehicleRestrictionRecord;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource\Pages;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de las RESTRICCIONES DE RUTEO por camión (R-04, Ola 0 "restricciones sin dueño"): tipos de carga
 * que admite, zonas permitidas/prohibidas y límites físicos por producto. El auto-planificador las lee en
 * lote y descarta asignaciones incompatibles.
 *
 * Toda escritura despacha un comando al CommandBus (SetVehicleRestrictions / RemoveVehicleRestrictions);
 * el record es solo proyección de lectura. El camión se muestra por su placa, nunca por el UUID.
 *
 * Fuera del menú: depende del camión. Se llega desde «Delivery Plans» (acción de cabecera) y desde la ficha
 * del vehículo con `?vehicle=<id>`, que acota la lista a ese camión.
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6).
 *
 * @extends resource<VehicleRestrictionRecord>
 */
final class VehicleRestrictionResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = VehicleRestrictionRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-no-symbol';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('truck restriction');
    }

    public static function getPluralModelLabel(): string
    {
        return __('truck restrictions');
    }

    /**
     * Título humano del registro: la placa del camión, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof VehicleRestrictionRecord) {
            return null;
        }

        return $record->vehicle->plate ?? '—';
    }

    /**
     * Eager-load del camión para pintar la placa sin N+1.
     *
     * @return Builder<VehicleRestrictionRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('vehicle');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vehicle_id')
                ->label(__('Vehicle'))
                ->required()
                ->native(false)
                ->searchable()
                // La PK es el camión: no se cambia al editar (se borra y se crea otra).
                ->disabled(static fn (string $operation): bool => $operation === 'edit')
                ->getSearchResultsUsing(static fn (string $search): array => self::vehicleSearch($search))
                ->getOptionLabelUsing(static fn (string $value): ?string => self::vehiclePlate($value)),
            TagsInput::make('allowed_load_types')
                ->label(__('Allowed load types'))
                ->helperText(__('Leave empty to accept any load type.'))
                ->suggestions(static fn (): array => self::knownValues('load_type')),
            TagsInput::make('allowed_zones')
                ->label(__('Allowed zones'))
                ->helperText(__('Leave empty to allow every zone.'))
                ->suggestions(static fn (): array => self::knownValues('zone')),
            TagsInput::make('forbidden_zones')
                ->label(__('Forbidden zones'))
                ->helperText(__('Forbidden zones win over allowed zones.'))
                ->suggestions(static fn (): array => self::knownValues('zone')),
            TextInput::make('max_height_m')
                ->label(__('Max product height (m)'))
                ->numeric()
                ->minValue(0.01)
                ->step(0.01),
            TextInput::make('max_weight_kg')
                ->label(__('Max product weight (kg)'))
                ->numeric()
                ->minValue(0.01)
                ->step(0.01),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Acota al camión al abrir desde su ficha (?vehicle=<id>).
            ->modifyQueryUsing(RequestScopedQuery::by('vehicle', 'vehicle_id'))
            ->columns([
                TextColumn::make('vehicle.plate')
                    ->label(__('Vehicle'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('allowed_load_types')
                    ->label(__('Allowed load types'))
                    ->badge()
                    ->placeholder(__('Any')),
                TextColumn::make('allowed_zones')
                    ->label(__('Allowed zones'))
                    ->badge()
                    ->placeholder(__('Any')),
                TextColumn::make('forbidden_zones')
                    ->label(__('Forbidden zones'))
                    ->badge()
                    ->color('danger')
                    ->placeholder(__('None')),
                TextColumn::make('max_height_m')
                    ->label(__('Max product height (m)'))
                    ->numeric(2)
                    ->placeholder('—'),
                TextColumn::make('max_weight_kg')
                    ->label(__('Max product weight (kg)'))
                    ->numeric(2)
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->native(false)
                    ->searchable()
                    // Solo camiones que tienen restricciones (datos reales, por placa).
                    ->options(static fn (): array => VehicleModel::query()
                        ->whereIn('id', VehicleRestrictionRecord::query()->select('vehicle_id'))
                        ->orderBy('plate')
                        ->pluck('plate', 'id')
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
                self::removeAction(),
            ]);
    }

    /**
     * Quitar las restricciones del camión: despacha RemoveVehicleRestrictions (nunca borra el record a mano).
     */
    public static function removeAction(): Action
    {
        return Action::make('removeRestrictions')
            ->label(__('Remove restrictions'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Remove truck restrictions'))
            ->modalDescription(__('The truck will accept any load type and zone again.'))
            ->action(static function (VehicleRestrictionRecord $record, CommandBus $commandBus): void {
                $commandBus->dispatch(new RemoveVehicleRestrictions((string) $record->getKey()));

                Notification::make()
                    ->title(__('Restrictions removed'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Arma el comando desde los datos del formulario (listas de etiquetas y límites opcionales).
     *
     * @param array<string, mixed> $data
     */
    public static function commandFrom(string $vehicleId, array $data): SetVehicleRestrictions
    {
        return new SetVehicleRestrictions(
            vehicleId: $vehicleId,
            allowedLoadTypes: self::tags($data['allowed_load_types'] ?? null),
            allowedZones: self::tags($data['allowed_zones'] ?? null),
            forbiddenZones: self::tags($data['forbidden_zones'] ?? null) ?? [],
            maxHeightM: self::decimal($data['max_height_m'] ?? null),
            maxWeightKg: self::decimal($data['max_weight_kg'] ?? null),
        );
    }

    /**
     * Lista de etiquetas del TagsInput; null si queda vacía (sin restricción).
     *
     * @return list<string>|null
     */
    public static function tags(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $tags = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $tags[] = trim($item);
            }
        }

        return $tags === [] ? null : $tags;
    }

    public static function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleRestrictions::route('/'),
            'create' => Pages\CreateVehicleRestriction::route('/create'),
            'edit' => Pages\EditVehicleRestriction::route('/{record}/edit'),
        ];
    }

    /**
     * Búsqueda de camiones por placa con datos reales de Flota: valor = id (lo que persiste el comando),
     * etiqueta = placa.
     *
     * @return array<string, string>
     */
    public static function vehicleSearch(string $search): array
    {
        /** @var array<string, string> $options */
        $options = VehicleModel::query()
            ->where('plate', 'ilike', '%'.trim($search).'%')
            ->orderBy('plate')
            ->limit(50)
            ->pluck('plate', 'id')
            ->all();

        return $options;
    }

    public static function vehiclePlate(string $vehicleId): ?string
    {
        $plate = VehicleModel::query()->whereKey($vehicleId)->value('plate');

        return is_string($plate) ? $plate : null;
    }

    /**
     * Valores ya usados en los requisitos de envíos (tipos de carga o zonas), como sugerencias reales.
     *
     * @return list<string>
     */
    private static function knownValues(string $column): array
    {
        $values = [];
        foreach (ShipmentRequirementRecord::query()->whereNotNull($column)->distinct()->orderBy($column)->limit(50)->pluck($column) as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
