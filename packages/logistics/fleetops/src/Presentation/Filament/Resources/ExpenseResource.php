<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\ExpenseRecord;
use Logistics\FleetOps\Presentation\Filament\Concerns\ResolvesVehiclePlate;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages\ViewExpense;

/**
 * Admin de gastos de flota (que no son combustible ni mantenimiento): peajes, multas, llantas, etc.
 *
 * Nota de arquitectura: Filament se apoya en ExpenseRecord como proyección de lectura. El alta NO escribe
 * el record: despacha RecordExpense al bus CQRS (ver CreateExpense). El importe se captura en bolivianos y
 * se convierte a centavos. La placa del vehículo se resuelve por lectura cruzada a `fleet_vehicles`.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class ExpenseResource extends Resource
{
    use ResolvesVehiclePlate;

    protected static ?string $model = ExpenseRecord::class;

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?int $navigationSort = 44;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-banknotes';
    }

    public static function getModelLabel(): string
    {
        return __('Expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expenses');
    }

    /**
     * Opciones valor→etiqueta de las categorías de gasto (texto en inglés desde el propio enum).
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        $options = [];
        foreach (ExpenseCategory::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando RecordExpense; el importe se captura en
     * bolivianos y se convierte a centavos en la página de creación.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vehicle_id')
                ->label(__('Vehicle'))
                ->required()
                ->native(false)
                ->searchable()
                ->getSearchResultsUsing(static fn (string $search): array => self::searchVehiclePlates($search))
                ->getOptionLabelUsing(static fn (string $value): string => self::vehiclePlate($value)),
            Select::make('category')
                ->label(__('Category'))
                ->options(self::categoryOptions())
                ->native(false)
                ->required(),
            TextInput::make('amount_bs')
                ->label(__('Amount (Bs)'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->required(),
            TextInput::make('currency')
                ->label(__('Currency'))
                ->default('BOB')
                ->required()
                ->maxLength(3),
            DateTimePicker::make('incurred_at')
                ->label(__('Incurred at'))
                ->native(false)
                ->seconds(false)
                ->default(now())
                ->required(),
            TextInput::make('description')
                ->label(__('Description'))
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('note')
                ->label(__('Note'))
                ->maxLength(1000)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Vista de detalle (solo lectura) de un gasto de flota.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Expense'))
                ->schema([
                    TextEntry::make('vehicle_id')
                        ->label(__('Vehicle'))
                        ->formatStateUsing(static fn (?string $state): string => self::vehiclePlate($state)),
                    TextEntry::make('category')
                        ->label(__('Category'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(ExpenseCategory::from($state)->label())),
                    TextEntry::make('amount_cents')
                        ->label(__('Amount (Bs)'))
                        ->formatStateUsing(static fn (int $state, ExpenseRecord $record): string => number_format($state / 100, 2).' '.$record->currency),
                    TextEntry::make('incurred_at')
                        ->label(__('Incurred at'))
                        ->dateTime(),
                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('note')
                        ->label(__('Note'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('incurred_at', 'desc')
            ->columns([
                // Placa legible del vehículo por lectura cruzada a fleet, nunca el UUID.
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (?string $state): string => self::vehiclePlate($state)),
                TextColumn::make('category')
                    ->label(__('Category'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(ExpenseCategory::from($state)->label())),
                TextColumn::make('amount_cents')
                    ->label(__('Amount (Bs)'))
                    ->formatStateUsing(static fn (int $state, ExpenseRecord $record): string => number_format($state / 100, 2).' '.$record->currency)
                    ->sortable(),
                TextColumn::make('incurred_at')
                    ->label(__('Incurred at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->native(false)
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => self::searchVehiclePlates($search))
                    ->getOptionLabelUsing(static fn (string $value): string => self::vehiclePlate($value)),
                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->options(self::categoryOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
        ];
    }
}
