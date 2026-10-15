<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\ActivateGeofence\ActivateGeofence;
use Logistics\Telemetry\Application\Command\DeactivateGeofence\DeactivateGeofence;
use Logistics\Telemetry\Application\Command\DeleteGeofence\DeleteGeofence;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\GeofenceRecord;
use Logistics\Telemetry\Presentation\Filament\Forms\GeofenceMapField;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages\CreateGeofence;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages\EditGeofence;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages\ListGeofences;
use Logistics\Telemetry\Presentation\Filament\Support\NodeNames;

/**
 * Admin de geocercas (zonas poligonales con semántica: CD, tienda, zona prohibida, descanso...).
 *
 * Nota de arquitectura: Filament se apoya en GeofenceRecord solo como proyección de lectura para
 * listar y precargar el formulario. Toda MUTACIÓN (definir, redibujar, renombrar, activar, desactivar,
 * borrar) se hace despachando comandos al CommandBus CQRS, nunca escribiendo el record. El polígono se
 * dibuja en un mapa Leaflet-Geoman (ver GeofenceMapField). Texto visible en inglés (regla 5);
 * comentarios en español (regla 6).
 */
final class GeofenceResource extends Resource
{
    protected static ?string $model = GeofenceRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationSort(): int
    {
        return 52;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-map-pin';
    }

    public static function getModelLabel(): string
    {
        return __('Geofence');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Geofences');
    }

    /**
     * Etiquetas humanas (inglés) de cada tipo de geocerca. Las claves son los valores del enum.
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        $options = [];
        foreach (GeofenceType::cases() as $type) {
            $options[$type->value] = __($type->label());
        }

        return $options;
    }

    /**
     * Color semántico del badge por tipo de geocerca.
     */
    private static function typeColor(string $type): string
    {
        return match ($type) {
            GeofenceType::ForbiddenZone->value => 'danger',
            GeofenceType::AuthorizedZone->value, GeofenceType::DistributionCenter->value => 'success',
            GeofenceType::Store->value, GeofenceType::Customer->value => 'info',
            GeofenceType::RestArea->value, GeofenceType::FuelStation->value => 'warning',
            default => 'gray',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            // Tipo: select no nativo con los GeofenceType. En edición queda bloqueado (no hay comando
            // para cambiar el tipo de una geocerca ya definida).
            Select::make('type')
                ->label(__('Type'))
                ->native(false)
                ->required()
                ->options(self::typeOptions())
                ->disabledOn('edit')
                ->dehydrated(),
            // Nodo logístico asociado (opcional): select buscable con datos reales de la red. Guarda el
            // id del nodo como referencia opaca. Bloqueado en edición (no hay comando que lo modifique).
            Select::make('node_ref')
                ->label(__('Logistics node'))
                ->native(false)
                ->searchable()
                ->options(static fn (): array => NodeRecord::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->disabledOn('edit')
                ->helperText(__('Optional node (distribution center, store) this geofence refers to.')),
            // Polígono dibujado en el mapa Leaflet-Geoman; su estado alimenta el comando.
            GeofenceMapField::make('polygon')
                ->label(__('Polygon'))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::typeOptions()[$state] ?? $state)
                    ->color(static fn (string $state): string => self::typeColor($state))
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),
                // Nombre legible del nodo de red, nunca el UUID (regla: ningún UUID visible).
                TextColumn::make('node_ref')
                    ->label(__('Logistics node'))
                    ->placeholder('—')
                    ->formatStateUsing(static fn (?string $state): string => $state === null ? '—' : (NodeNames::label($state) ?? '—'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->native(false)
                    ->options(self::typeOptions()),
                TernaryFilter::make('active')
                    ->label(__('Active')),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                // Activar: solo visible si está inactiva. Despacha ActivateGeofence al bus.
                Action::make('activate')
                    ->label(__('Activate'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(static fn (GeofenceRecord $record): bool => !$record->active)
                    ->action(static function (GeofenceRecord $record): void {
                        app(CommandBus::class)->dispatch(new ActivateGeofence(
                            geofenceId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Geofence activated'))
                            ->success()
                            ->send();
                    }),
                // Desactivar: solo visible si está activa. Despacha DeactivateGeofence al bus.
                Action::make('deactivate')
                    ->label(__('Deactivate'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(static fn (GeofenceRecord $record): bool => $record->active)
                    ->action(static function (GeofenceRecord $record): void {
                        app(CommandBus::class)->dispatch(new DeactivateGeofence(
                            geofenceId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Geofence deactivated'))
                            ->success()
                            ->send();
                    }),
                // Borrar: despacha DeleteGeofence al bus (no borra el record directamente).
                DeleteAction::make()
                    ->using(static function (GeofenceRecord $record): bool {
                        app(CommandBus::class)->dispatch(new DeleteGeofence(
                            geofenceId: (string) $record->getKey(),
                        ));

                        return true;
                    }),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListGeofences::route('/'),
            'create' => CreateGeofence::route('/create'),
            'edit' => EditGeofence::route('/{record}/edit'),
        ];
    }
}
