<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use App\Filament\Support\RequestScopedQuery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Fleet\Domain\Contract\VehicleFitnessLog;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleFitnessLogModel;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleFitnessLogResource\Pages\ListVehicleFitnessLog;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shared\Presentation\Filament\Concerns\PlainNavigationLabel;

/**
 * Bitácora de aptitud de los vehículos (auditoría #27), solo lectura: cuándo un vehículo quedó NO APTO
 * por un checklist crítico y quién lo habilitó tras la reparación. Es append-only: no hay alta, edición
 * ni borrado. Su hogar es la ficha 360 del vehículo (enlace filtrado por ?vehicle=<id>).
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md); nunca se muestra un UUID.
 */
final class VehicleFitnessLogResource extends Resource
{
    use OfficeOnly;
    use PlainNavigationLabel;

    protected static ?string $model = VehicleFitnessLogModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shield-exclamation';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Fitness log entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Vehicle fitness log');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Eager-load del vehículo para mostrar su placa sin N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('vehicle');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(RequestScopedQuery::by('vehicle', 'vehicle_id'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('When'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('vehicle.plate')
                    ->label(__('Vehicle'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('action')
                    ->label(__('Action'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === VehicleFitnessLog::ACTION_CLEARED ? 'success' : 'danger')
                    ->formatStateUsing(static fn (string $state): string => $state === VehicleFitnessLog::ACTION_CLEARED
                        ? __('Cleared for dispatch')
                        : __('Marked unfit')),
                TextColumn::make('reason')
                    ->label(__('Reason / repair note'))
                    ->placeholder('—')
                    ->wrap()
                    ->limit(120),
                TextColumn::make('failed_items')
                    ->label(__('Failed items'))
                    ->placeholder('—')
                    ->formatStateUsing(static fn ($state): string => is_array($state) ? implode(', ', array_map('strval', $state)) : (string) $state),
                TextColumn::make('actor_label')
                    ->label(__('By'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label(__('Action'))
                    ->native(false)
                    ->options([
                        VehicleFitnessLog::ACTION_MARKED_UNFIT => __('Marked unfit'),
                        VehicleFitnessLog::ACTION_CLEARED => __('Cleared for dispatch'),
                    ]),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVehicleFitnessLog::route('/'),
        ];
    }
}
