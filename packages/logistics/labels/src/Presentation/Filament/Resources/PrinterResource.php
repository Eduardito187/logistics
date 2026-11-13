<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Logistics\Labels\Application\Command\RemovePrinter\RemovePrinter;
use Logistics\Labels\Domain\ValueObject\PrinterEndpoint;
use Logistics\Labels\Domain\ValueObject\PrinterLanguage;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\PrinterRecord;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages\CreatePrinter;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages\EditPrinter;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages\ListPrinters;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Registro central de impresoras de red por sede (alta/baja/edición).
 *
 * La app las ofrece en "Impresoras de tu sede" (GET /api/v1/app/printers): en sedes con WiFi y cable
 * separados el teléfono no puede descubrirlas solo. Cambiar la IP aquí se refleja en la app sin
 * reinstalar. Filament usa PrinterRecord solo para listar/precargar; toda mutación va por el CommandBus.
 * Texto visible en inglés (regla 5); la sede se muestra por nombre, nunca por UUID.
 */
final class PrinterResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = PrinterRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    // Anidada BAJO "Configuración" en la navegación (el dueño la ve como opción de configuración): cuelga del
    // ítem Configuration en vez de ser un ítem suelto del menú principal, dentro del mismo grupo Administration.
    public static function getNavigationParentItem(): ?string
    {
        return __('Configuration');
    }

    public static function getNavigationSort(): int
    {
        return 61;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-printer';
    }

    public static function getModelLabel(): string
    {
        return __('Network printer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Network printers');
    }

    /**
     * @return array<string, string>
     */
    public static function languageOptions(): array
    {
        $options = [];
        foreach (PrinterLanguage::cases() as $language) {
            $options[$language->value] = __($language->label());
        }

        return $options;
    }

    /**
     * Nodos de red (sedes) por nombre, para el select buscable y para mostrar la sede sin UUID.
     *
     * @return array<string, string>
     */
    public static function nodeOptions(): array
    {
        // once(): memoizado por request (no estático), así un nodo recién creado aparece en la siguiente.
        return once(static function (): array {
            try {
                /** @var array<string, string> */
                return DB::table('network_nodes')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code'])
                    ->mapWithKeys(static fn (object $node): array => [
                        (string) $node->id => "{$node->name} ({$node->code})",
                    ])
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->placeholder('Zebra Dock 2')
                ->required()
                ->maxLength(120),
            TextInput::make('model')
                ->label(__('Model'))
                ->placeholder('ZT411')
                ->maxLength(120),
            TextInput::make('host')
                ->label(__('Host'))
                ->helperText(__('IP address or hostname reachable from the site network.'))
                ->placeholder('zebra-dock2.local')
                ->required()
                ->maxLength(255)
                ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        PrinterEndpoint::of((string) $value);
                    } catch (\Throwable) {
                        $fail(__('Enter a valid IP address or hostname.'));
                    }
                }),
            TextInput::make('port')
                ->label(__('Port'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(65535)
                ->default(PrinterEndpoint::DEFAULT_PORT)
                ->required(),
            Select::make('language')
                ->label(__('Print language'))
                ->native(false)
                ->options(self::languageOptions())
                ->default(PrinterLanguage::Zpl->value)
                ->required(),
            // Sede: select buscable con nodos reales de la red. Vacío = compartida (todas las sedes la ven).
            Select::make('node_id')
                ->label(__('Site'))
                ->native(false)
                ->searchable()
                ->options(static fn (): array => self::nodeOptions())
                ->placeholder(__('Shared (all sites)'))
                ->helperText(__('Leave empty to offer this printer at every site.')),
            TextInput::make('location')
                ->label(__('Location'))
                ->placeholder('Dock 2')
                ->maxLength(120),
            Toggle::make('active')
                ->label(__('Active'))
                ->helperText(__('Inactive printers are not offered in the app.'))
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->description(static fn (PrinterRecord $record): ?string => $record->model)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('host')
                    ->label(__('Address'))
                    ->formatStateUsing(static fn (PrinterRecord $record): string => "{$record->host}:{$record->port}")
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('language')
                    ->label(__('Print language'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::languageOptions()[$state] ?? $state)
                    ->color(static fn (string $state): string => $state === PrinterLanguage::Zpl->value ? 'info' : 'gray'),
                TextColumn::make('node_id')
                    ->label(__('Site'))
                    ->formatStateUsing(static fn (?string $state): string => $state === null ? __('Shared (all sites)') : (self::nodeOptions()[$state] ?? '—'))
                    ->placeholder(__('Shared (all sites)')),
                TextColumn::make('location')
                    ->label(__('Location'))
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('node_id')
                    ->label(__('Site'))
                    ->native(false)
                    ->searchable()
                    ->options(static fn (): array => self::nodeOptions()),
                TernaryFilter::make('active')
                    ->label(__('Active')),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(static function (PrinterRecord $record): bool {
                        app(CommandBus::class)->dispatch(new RemovePrinter(printerId: (string) $record->getKey()));

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
            'index' => ListPrinters::route('/'),
            'create' => CreatePrinter::route('/create'),
            'edit' => EditPrinter::route('/{record}/edit'),
        ];
    }
}
