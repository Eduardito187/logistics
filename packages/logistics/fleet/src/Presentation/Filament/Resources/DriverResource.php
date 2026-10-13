<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\CreateDriver;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\ListDrivers;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\ViewDriver;

/**
 * Resource de administración de choferes de la flota.
 *
 * Filament usa DriverModel para el lado de LECTURA (tabla y formulario). El alta despacha el comando
 * RegisterDriver por el CommandBus. No se expone edición: el dominio todavía no tiene un comando para
 * modificar un chofer ya registrado; cuando exista, se agrega la página Edit.
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class DriverResource extends Resource
{
    protected static ?string $model = DriverModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Driver');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Drivers');
    }

    // Título legible del registro: el nombre del chofer, no el UUID.
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(150),

            TextInput::make('license_number')
                ->label(__('License number'))
                ->required()
                ->maxLength(50),

            DatePicker::make('license_expiry')
                ->label(__('License expiry'))
                ->native(false)
                ->required()
                ->format('Y-m-d'),

            TextInput::make('license_document_type')
                ->label(__('License document type'))
                ->default('driver_license')
                ->maxLength(50),

            Select::make('enabled_vehicle_ids')
                ->label(__('Enabled vehicles'))
                ->multiple()
                ->native(false)
                ->searchable()
                // Datos reales de la flota: búsqueda por placa.
                ->getSearchResultsUsing(static fn (string $search): array => VehicleModel::query()
                    ->where('plate', 'ilike', "%{$search}%")
                    ->orderBy('plate')
                    ->limit(50)
                    ->pluck('plate', 'id')
                    ->all())
                ->getOptionLabelsUsing(static fn (array $values): array => VehicleModel::query()
                    ->whereKey($values)
                    ->pluck('plate', 'id')
                    ->all()),

            Toggle::make('active')
                ->label(__('Active'))
                ->default(true),
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
                TextColumn::make('license_number')
                    ->label(__('License number'))
                    ->searchable(),
                TextColumn::make('license_expiry')
                    ->label(__('License expiry'))
                    ->date('Y-m-d')
                    ->sortable()
                    // Badge por vencimiento de la licencia: rojo si venció, ámbar si vence pronto.
                    ->badge()
                    ->color(static fn (?string $state): string => self::expiryColor($state)),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('license_number')->label(__('License number'))->copyable(),
            TextEntry::make('license_expiry')->label(__('License expiry'))->date('Y-m-d'),
            IconEntry::make('active')->label(__('Active'))->boolean(),
            TextEntry::make('enabled_vehicle_ids')
                ->label(__('Enabled vehicles'))
                ->badge()
                ->placeholder('—')
                // Muestra las placas reales de los vehículos habilitados para este chofer.
                ->formatStateUsing(static fn (string $state): string => VehicleModel::query()
                    ->whereKey($state)
                    ->value('plate') ?? $state),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'view' => ViewDriver::route('/{record}'),
        ];
    }

    /**
     * Color semántico del badge de vencimiento de la licencia según cuánto falta para que venza.
     *
     * Rojo (danger) si ya venció, ámbar (warning) si vence dentro de 30 días, verde (success) si sigue
     * vigente, gris si no hay fecha. La fecha llega como texto 'Y-m-d' (Postgres).
     */
    private static function expiryColor(?string $expiresOn): string
    {
        if ($expiresOn === null || $expiresOn === '') {
            return 'gray';
        }

        $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiresOn);
        if ($expiry === false) {
            return 'gray';
        }

        $today = new \DateTimeImmutable('today');
        if ($expiry < $today) {
            return 'danger';
        }

        $daysLeft = (int) $today->diff($expiry)->format('%a');

        return $daysLeft <= 30 ? 'warning' : 'success';
    }
}
