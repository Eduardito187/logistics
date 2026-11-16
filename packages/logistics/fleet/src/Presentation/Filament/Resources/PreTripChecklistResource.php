<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use App\Filament\Support\RequestScopedQuery;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\PreTripInspectionModel;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages\ListPreTripChecklists;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages\ViewPreTripChecklist;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shared\Presentation\Filament\Concerns\PlainNavigationLabel;

/**
 * Resource de administración del checklist pre-salida (inspección ejecutada sobre un vehículo).
 *
 * Filament usa PreTripInspectionModel para LECTURA; el alta despacha ConductPreTripChecklist por el
 * CommandBus. No se expone edición: una inspección es un registro inmutable de lo verificado ese día.
 * El resultado (apta / no apta) lo deriva el dominio de que el checklist quede completo.
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class PreTripChecklistResource extends Resource
{
    // Fuera del menú: este recurso DEPENDE del vehículo; su hogar es la ficha 360 del vehículo
    // (sección «Gestión del vehículo»), que enlaza a su lista. No es un ítem suelto del menú.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // SOLO LECTURA desde el admin: el checklist pre-salida se REALIZA desde el camión (app del chofer,
    // ConductPreTripChecklist). En el admin no se crea ni se edita; solo se consulta lo que se verificó.
    public static function canCreate(): bool
    {
        return false;
    }

    use OfficeOnly;
    use PlainNavigationLabel;

    protected static ?string $model = PreTripInspectionModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 36;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Pre-trip checklist');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Pre-trip checklists');
    }

    /**
     * Título legible del registro: placa del vehículo y fecha de la inspección, nunca el UUID. Una
     * inspección no tiene un único campo humano, así que se compone desde la relación ya cargada.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof PreTripInspectionModel) {
            return null;
        }

        $plate = $record->vehicle->plate ?? '—';

        return "{$plate} · {$record->performed_on}";
    }

    /**
     * Eager-load de vehículo y chofer para que `vehicle.plate` y `driver.name` no disparen N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'driver']);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Acota a la entidad padre al abrir desde su ficha 360 (?vehicle=<id>).
            ->modifyQueryUsing(RequestScopedQuery::by('vehicle', 'vehicle_id'))
            ->columns([
                // Placa legible del vehículo vía la relación Eloquent (eager-cargada), no el UUID.
                TextColumn::make('vehicle.plate')
                    ->label(__('Vehicle'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                // Nombre legible del chofer vía la relación Eloquent (eager-cargada), no el UUID.
                TextColumn::make('driver.name')
                    ->label(__('Driver'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('passed')
                    ->label(__('Result'))
                    ->badge()
                    ->color(static fn (bool $state): string => $state ? 'success' : 'danger')
                    ->formatStateUsing(static fn (bool $state): string => $state ? __('Passed') : __('Failed')),
                TextColumn::make('fit_to_dispatch')
                    ->label(__('Fit to dispatch'))
                    ->badge()
                    ->color(static fn (bool $state): string => $state ? 'success' : 'danger')
                    ->formatStateUsing(static fn (bool $state): string => $state ? __('Fit') : __('Unfit')),
                TextColumn::make('performed_on')
                    ->label(__('Performed on'))
                    ->date('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('passed')
                    ->label(__('Passed')),
                TernaryFilter::make('fit_to_dispatch')
                    ->label(__('Fit to dispatch')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('performed_on', 'desc');
    }

    /**
     * Vista de detalle SOLO LECTURA de una inspección: deja claro QUIÉN la hizo (chofer), EN QUÉ camión,
     * CUÁNDO y el RESULTADO (apta / no apta), seguido del listado de opciones verificadas con su resultado.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Checklist'))
                ->columns(2)
                ->schema([
                    TextEntry::make('vehicle.plate')
                        ->label(__('Vehicle'))
                        ->placeholder('—'),
                    TextEntry::make('driver.name')
                        ->label(__('Driver'))
                        ->placeholder('—'),
                    TextEntry::make('performed_on')->label(__('Performed on'))->dateTime('Y-m-d H:i'),
                    TextEntry::make('passed')
                        ->label(__('Result'))
                        ->badge()
                        ->color(static fn (bool $state): string => $state ? 'success' : 'danger')
                        ->formatStateUsing(static fn (bool $state): string => $state ? __('Passed') : __('Failed')),
                    IconEntry::make('fit_to_dispatch')->label(__('Fit to dispatch'))->boolean(),
                    TextEntry::make('evaluation.failed_critical')
                        ->label(__('Failed critical items'))
                        ->placeholder('—')
                        ->formatStateUsing(static fn ($state): string => is_array($state) && $state !== [] ? implode(', ', array_map('strval', $state)) : '—'),
                ]),
            Section::make(__('Checked items'))
                ->schema([
                    // Listado de las opciones verificadas con su resultado (✓ verificada / ✗ pendiente), una
                    // por línea, legible. Es solo lectura: la inspección es un registro inmutable del día.
                    TextEntry::make('items')
                        ->hiddenLabel()
                        ->placeholder('—')
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->state(static function (PreTripInspectionModel $record): array {
                            $items = $record->items;
                            if (!is_array($items) || $items === []) {
                                return [];
                            }
                            $lines = [];
                            foreach ($items as $name => $checked) {
                                $lines[] = ((bool) $checked ? '✓ ' : '✗ ').(string) $name;
                            }

                            return $lines;
                        }),
                ]),
            Section::make(__('Notes'))
                ->schema([
                    TextEntry::make('notes')->hiddenLabel()->placeholder('—'),
                ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPreTripChecklists::route('/'),
            'view' => ViewPreTripChecklist::route('/{record}'),
        ];
    }
}
