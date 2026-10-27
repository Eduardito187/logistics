<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Presentation\Filament\PlannedStopResource\Pages;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de solo lectura de las paradas del plan (la asignación de un envío a una posición de reparto).
 *
 * Las paradas nacen y se reordenan SIEMPRE por los flujos transaccionales del contexto (comandos
 * AssignShipmentToVehicle y ReorderStops, expuestos como acciones en PlanResource; dominio + outbox),
 * nunca como fila editable: por eso este resource no crea, edita ni borra. Da una vista global y
 * buscable de todas las paradas (por referencia de pedido, por día o por placa), útil para ubicar en
 * qué plan y en qué posición quedó un envío. Muestra datos humanos (referencia, cliente, día, placa) en
 * lugar de los UUID crudos, vía las relaciones Eloquent hacia Shipments y Fleet.
 *
 * Todo el texto visible va en inglés (regla 5 del proyecto). Comentarios en español.
 *
 * @extends resource<PlannedStopRecord>
 */
final class PlannedStopResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = PlannedStopRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'ds-flag';

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Planned Stops');
    }

    public static function getModelLabel(): string
    {
        return __('planned stop');
    }

    public static function getPluralModelLabel(): string
    {
        return __('planned stops');
    }

    /**
     * Título humano del registro: referencia del envío y posición, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof PlannedStopRecord) {
            return null;
        }

        $reference = $record->shipment->external_id ?? $record->shipment_id;

        return "#{$record->sequence} · {$reference}";
    }

    /**
     * Eager-load del envío y del plan (con su camión) para evitar N+1 al pintar referencias y placas.
     *
     * @return Builder<PlannedStopRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['shipment', 'plan.vehicle']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Día del plan y placa del camión vía relación con Planning/Fleet (no UUID plan_id).
                TextColumn::make('plan.plan_date')
                    ->label(__('Plan date'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('plan.vehicle.plate')
                    ->label(__('Vehicle'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('sequence')
                    ->label('#')
                    ->sortable()
                    ->alignCenter(),
                // Referencia y cliente del pedido vía relación con Shipments (no UUID shipment_id).
                TextColumn::make('shipment.external_id')
                    ->label(__('Shipment'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('shipment.customer_name')
                    ->label(__('Customer'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('eta')
                    ->label(__('ETA'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('eta_minutes')
                    ->label(__('ETA (min from departure)'))
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('lane')
                    ->label(__('Load lane'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Los UUID (plan_id, shipment_id y la pk de la parada) no se muestran: el plan va por
                // fecha + placa y el envío por su referencia/cliente vía las relaciones Eloquent.
            ])
            ->filters([
                Filter::make('scheduled')
                    ->label(__('With ETA'))
                    ->query(static fn (Builder $query): Builder => $query->whereNotNull('eta')),
                Filter::make('loaded')
                    ->label(__('With load lane'))
                    ->query(static fn (Builder $query): Builder => $query->whereNotNull('lane')),
            ])
            ->defaultSort('sequence');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Stop'))
                ->schema([
                    TextEntry::make('plan.plan_date')->label(__('Plan date'))->date()->placeholder('—'),
                    TextEntry::make('plan.vehicle.plate')->label(__('Vehicle'))->placeholder('—'),
                    TextEntry::make('sequence')->label(__('Sequence')),
                    TextEntry::make('shipment.external_id')->label(__('Shipment'))->placeholder('—'),
                    TextEntry::make('shipment.customer_name')->label(__('Customer'))->placeholder('—'),
                ])
                ->columns(2),
            Section::make(__('Schedule & load'))
                ->schema([
                    TextEntry::make('eta')->label(__('ETA'))->dateTime()->placeholder('—'),
                    TextEntry::make('eta_minutes')->label(__('ETA (min from departure)'))->placeholder('—'),
                    TextEntry::make('lane')->label(__('Load lane'))->placeholder('—'),
                ])
                ->columns(2),
            // Sin sección de identificadores: los UUID (pk de la parada, plan_id, shipment_id) no se
            // muestran. El plan se reconoce por fecha + placa y el envío por su referencia/cliente.
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlannedStops::route('/'),
            'view' => Pages\ViewPlannedStop::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // Las paradas nacen por comando (AssignShipmentToVehicle); el admin no las crea.
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        // El orden y la ETA solo cambian por comandos de aplicación (ReorderStops); el admin no las edita.
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }
}
