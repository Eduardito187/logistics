<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\FleetOps\Application\Command\ActivatePlan\ActivatePlan;
use Logistics\FleetOps\Application\Command\DeactivatePlan\DeactivatePlan;
use Logistics\FleetOps\Application\Command\RecordServiceDone\RecordServiceDone;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenancePlanRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource\Pages\CreateMaintenancePlan;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource\Pages\ListMaintenancePlans;
use Logistics\FleetOps\Presentation\Filament\Support\VehiclePlates;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de planes de mantenimiento preventivo (por km y/o por días) de la flota propia.
 *
 * Nota de arquitectura: MaintenancePlanRecord es proyección de lectura. Toda MUTACIÓN (definir, registrar
 * servicio hecho, activar/desactivar) despacha su comando al bus CQRS; nunca escribe el record. Registrar
 * un servicio recalcula el próximo vencimiento en el agregado.
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class MaintenancePlanResource extends Resource
{
    protected static ?string $model = MaintenancePlanRecord::class;

    protected static ?string $recordTitleAttribute = 'service_kind';

    public static function getNavigationGroup(): string
    {
        return __('Fleet Ops');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public static function getModelLabel(): string
    {
        return __('Maintenance plan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Maintenance plans');
    }

    /**
     * Opciones valor→etiqueta de las clases de servicio (texto en inglés desde el enum).
     *
     * @return array<string, string>
     */
    public static function serviceKindOptions(): array
    {
        $options = [];
        foreach (ServiceKind::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando DefineMaintenancePlan (por km y/o días).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vehicle_id')
                ->label(__('Vehicle'))
                ->native(false)
                ->searchable()
                ->required()
                ->getSearchResultsUsing(static fn (string $search): array => VehiclePlates::search($search))
                ->getOptionLabelUsing(static fn (string $value): ?string => VehiclePlates::label($value)),
            Select::make('service_kind')
                ->label(__('Service kind'))
                ->options(self::serviceKindOptions())
                ->native(false)
                ->required(),
            TextInput::make('interval_km')
                ->label(__('Interval (km)'))
                ->numeric()
                ->minValue(1),
            TextInput::make('interval_days')
                ->label(__('Interval (days)'))
                ->numeric()
                ->minValue(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (string $state): string => VehiclePlates::label($state) ?? $state)
                    ->sortable(),
                TextColumn::make('service_kind')
                    ->label(__('Service kind'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(ServiceKind::from($state)->label())),
                TextColumn::make('interval_km')
                    ->label(__('Interval (km)'))
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('interval_days')
                    ->label(__('Interval (days)'))
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('next_due_km')
                    ->label(__('Next due (km)'))
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('next_due_at')
                    ->label(__('Next due at'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->options(static fn (): array => VehiclePlates::options())
                    ->native(false)
                    ->searchable(),
            ])
            ->recordActions([
                // Registrar servicio hecho: recalcula el próximo vencimiento. Odómetro opcional.
                Action::make('record_service_done')
                    ->label(__('Record service done'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->modalHeading(__('Record service done'))
                    ->modalSubmitActionLabel(__('Record service done'))
                    ->schema([
                        TextInput::make('odometer_km')
                            ->label(__('Odometer'))
                            ->numeric()
                            ->minValue(0),
                    ])
                    ->action(static function (MaintenancePlanRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new RecordServiceDone(
                            planId: (string) $record->getKey(),
                            odometerKm: isset($data['odometer_km']) && is_numeric($data['odometer_km']) ? (int) $data['odometer_km'] : null,
                        ));

                        Notification::make()
                            ->title(__('Service recorded'))
                            ->success()
                            ->send();
                    }),
                // Activar: vuelve a vigilar vencimientos. Solo visible si está inactivo.
                Action::make('activate')
                    ->label(__('Activate'))
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Activate plan'))
                    ->visible(static fn (MaintenancePlanRecord $record): bool => $record->active === false)
                    ->action(static function (MaintenancePlanRecord $record): void {
                        app(CommandBus::class)->dispatch(new ActivatePlan(
                            planId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Plan activated'))
                            ->success()
                            ->send();
                    }),
                // Desactivar: deja de vigilar vencimientos. Solo visible si está activo.
                Action::make('deactivate')
                    ->label(__('Deactivate'))
                    ->icon('heroicon-o-pause')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('Deactivate plan'))
                    ->visible(static fn (MaintenancePlanRecord $record): bool => $record->active === true)
                    ->action(static function (MaintenancePlanRecord $record): void {
                        app(CommandBus::class)->dispatch(new DeactivatePlan(
                            planId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Plan deactivated'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMaintenancePlans::route('/'),
            'create' => CreateMaintenancePlan::route('/create'),
        ];
    }
}
