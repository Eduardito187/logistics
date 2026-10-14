<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\FleetOps\Application\Command\CancelMaintenance\CancelMaintenance;
use Logistics\FleetOps\Application\Command\CompleteMaintenance\CompleteMaintenance;
use Logistics\FleetOps\Application\Command\StartMaintenance\StartMaintenance;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource\Pages\CreateMaintenanceOrder;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource\Pages\ListMaintenanceOrders;
use Logistics\FleetOps\Presentation\Filament\Support\VehiclePlates;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de órdenes de mantenimiento de la flota propia.
 *
 * Nota de arquitectura: MaintenanceOrderRecord es proyección de lectura. Toda MUTACIÓN del ciclo de vida
 * (agendar, iniciar, completar, cancelar) despacha su comando al bus CQRS, nunca escribe el record. Las
 * acciones de fila se muestran según el estado, respetando las transiciones que protege el agregado.
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class MaintenanceOrderResource extends Resource
{
    protected static ?string $model = MaintenanceOrderRecord::class;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): string
    {
        return __('Fleet Ops');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-wrench-screwdriver';
    }

    public static function getModelLabel(): string
    {
        return __('Maintenance order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Maintenance orders');
    }

    /**
     * Opciones valor→etiqueta de los tipos de mantenimiento (texto en inglés desde el enum).
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        $options = [];
        foreach (MaintenanceType::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Opciones valor→etiqueta de los estados de la orden (texto en inglés desde el enum).
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];
        foreach (MaintenanceStatus::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Formulario de alta (agendar). Solo los campos que admite el comando ScheduleMaintenance.
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
            Select::make('type')
                ->label(__('Type'))
                ->options(self::typeOptions())
                ->native(false)
                ->required(),
            TextInput::make('odometer_km')
                ->label(__('Odometer'))
                ->numeric()
                ->minValue(0),
            TextInput::make('workshop')
                ->label(__('Workshop'))
                ->maxLength(255),
            DateTimePicker::make('scheduled_at')
                ->label(__('Scheduled at'))
                ->native(false)
                ->seconds(false)
                ->default(now())
                ->required(),
            TextInput::make('note')
                ->label(__('Note'))
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (string $state): string => VehiclePlates::label($state) ?? $state)
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(MaintenanceType::from($state)->label())),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        MaintenanceStatus::Completed->value => 'success',
                        MaintenanceStatus::InProgress->value => 'warning',
                        MaintenanceStatus::Cancelled->value => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (string $state): string => __(MaintenanceStatus::from($state)->label())),
                TextColumn::make('total_cost_cents')
                    ->label(__('Total (Bs)'))
                    ->formatStateUsing(static fn (int $state): string => number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('scheduled_at')
                    ->label(__('Scheduled at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label(__('Completed at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->options(static fn (): array => VehiclePlates::options())
                    ->native(false)
                    ->searchable(),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statusOptions())
                    ->native(false),
            ])
            ->recordActions([
                // Iniciar: el vehículo entra al taller. Solo visible si está agendada.
                Action::make('start')
                    ->label(__('Start'))
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('Start maintenance'))
                    ->visible(static fn (MaintenanceOrderRecord $record): bool => $record->status === MaintenanceStatus::Scheduled->value)
                    ->action(static function (MaintenanceOrderRecord $record): void {
                        app(CommandBus::class)->dispatch(new StartMaintenance(
                            orderId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Maintenance started'))
                            ->success()
                            ->send();
                    }),
                // Completar: captura ítems (repuestos + mano de obra, en Bs) y cierra la orden. Solo si está en curso.
                Action::make('complete')
                    ->label(__('Complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->modalHeading(__('Complete maintenance'))
                    ->modalSubmitActionLabel(__('Complete'))
                    ->visible(static fn (MaintenanceOrderRecord $record): bool => $record->status === MaintenanceStatus::InProgress->value)
                    ->schema([
                        TextInput::make('currency')
                            ->label(__('Currency'))
                            ->default('BOB')
                            ->required()
                            ->maxLength(3),
                        Repeater::make('items')
                            ->label(__('Items'))
                            ->schema([
                                TextInput::make('description')
                                    ->label(__('Description'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('qty')
                                    ->label(__('Quantity'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required(),
                                TextInput::make('part_cost_bs')
                                    ->label(__('Part cost (Bs)'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->default(0)
                                    ->required(),
                                TextInput::make('labor_cost_bs')
                                    ->label(__('Labor cost (Bs)'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->default(0)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->addActionLabel(__('Add item')),
                    ])
                    ->action(static function (MaintenanceOrderRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new CompleteMaintenance(
                            orderId: (string) $record->getKey(),
                            items: self::normalizeItems($data['items'] ?? []),
                            currency: (string) $data['currency'],
                        ));

                        Notification::make()
                            ->title(__('Maintenance completed'))
                            ->success()
                            ->send();
                    }),
                // Cancelar: la orden no se ejecutará. Visible mientras no esté completada ni cancelada.
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Cancel maintenance'))
                    ->visible(static fn (MaintenanceOrderRecord $record): bool => in_array(
                        $record->status,
                        [MaintenanceStatus::Scheduled->value, MaintenanceStatus::InProgress->value],
                        true,
                    ))
                    ->action(static function (MaintenanceOrderRecord $record): void {
                        app(CommandBus::class)->dispatch(new CancelMaintenance(
                            orderId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Maintenance cancelled'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * Normaliza los ítems del repeater (costos en Bs) a la forma primitiva del comando (centavos).
     *
     * @return list<array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}>
     */
    private static function normalizeItems(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = [
                'description' => is_string($item['description'] ?? null) ? $item['description'] : '',
                'qty' => is_numeric($item['qty'] ?? null) ? (int) $item['qty'] : 1,
                'part_cost_cents' => is_numeric($item['part_cost_bs'] ?? null) ? (int) round(((float) $item['part_cost_bs']) * 100) : 0,
                'labor_cost_cents' => is_numeric($item['labor_cost_bs'] ?? null) ? (int) round(((float) $item['labor_cost_bs']) * 100) : 0,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceOrders::route('/'),
            'create' => CreateMaintenanceOrder::route('/create'),
        ];
    }
}
