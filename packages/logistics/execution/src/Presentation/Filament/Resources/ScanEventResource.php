<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource\Pages\ListScanEvents;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource\Pages\ViewScanEvent;
use Logistics\Execution\Presentation\Filament\Support\UserNames;

/**
 * Admin de solo lectura de los escaneos de terreno (preparación, carga, descarga, prueba de entrega
 * y recepción en nodo).
 *
 * Cada escaneo es un hecho inmutable (append-only): hora, GPS, usuario y dispositivo. El admin los
 * observa para operación y reconciliación, nunca los crea ni los edita; por eso no hay formulario de
 * alta/edición. Las escrituras de dominio pasan siempre por los buses CQRS en la capa HTTP.
 *
 * Todo el texto visible va en inglés (regla CLAUDE.md #5).
 *
 * @extends resource<ScanEventRecord>
 */
final class ScanEventResource extends Resource
{
    protected static ?string $model = ScanEventRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 36;

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Scan events');
    }

    public static function getModelLabel(): string
    {
        return __('scan event');
    }

    public static function getPluralModelLabel(): string
    {
        return __('scan events');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Eager-load de la entrega y su pedido para resolver las etiquetas legibles (pedido, vehículo)
     * sin N+1 al listar escaneos.
     *
     * @return Builder<ScanEventRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['delivery.shipment', 'delivery.vehicle']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::typeLabel($state))
                    ->sortable(),
                TextColumn::make('delivery.shipment.external_id')
                    ->label(__('Order'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('delivery.vehicle.plate')
                    ->label(__('Vehicle'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('unit')
                    ->label(__('Unit'))
                    ->state(static fn (ScanEventRecord $record): string => self::unitLabel($record))
                    ->placeholder('—'),
                TextColumn::make('unit_id')
                    ->label(__('Unit ID'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                // user_id se muestra como el NOMBRE del operador (users.name), nunca el id crudo.
                TextColumn::make('user_id')
                    ->label(__('User'))
                    ->formatStateUsing(static fn (?string $state): string => UserNames::label($state))
                    ->toggleable(),
                TextColumn::make('lat')
                    ->label(__('Latitude'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lng')
                    ->label(__('Longitude'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('occurred_at')
                    ->label(__('Occurred at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->native(false)
                    ->options(self::typeOptions()),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::typeLabel($state)),
                TextEntry::make('delivery.shipment.external_id')->label(__('Order'))->placeholder('—'),
                TextEntry::make('delivery.vehicle.plate')->label(__('Vehicle'))->placeholder('—'),
                TextEntry::make('unit')
                    ->label(__('Unit'))
                    ->state(static fn (ScanEventRecord $record): string => self::unitLabel($record))
                    ->placeholder('—'),
                TextEntry::make('unit_id')->label(__('Unit ID'))->copyable()->fontFamily('mono'),
                TextEntry::make('occurred_at')->label(__('Occurred at'))->dateTime(),
                // user_id se muestra como el NOMBRE del operador (users.name), nunca el id crudo.
                TextEntry::make('user_id')
                    ->label(__('User'))
                    ->formatStateUsing(static fn (?string $state): string => UserNames::label($state)),
                TextEntry::make('lat')->label(__('Latitude')),
                TextEntry::make('lng')->label(__('Longitude')),
                TextEntry::make('created_at')->label(__('Recorded at'))->dateTime(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListScanEvents::route('/'),
            'view' => ViewScanEvent::route('/{record}'),
        ];
    }

    /**
     * Opciones del tipo de escaneo como value → label en inglés.
     *
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];
        foreach (ScanType::cases() as $case) {
            $options[$case->value] = self::typeLabel($case->value);
        }

        return $options;
    }

    /**
     * Etiqueta humana del bulto escaneado.
     *
     * `scan_events.unit_id` guarda el CÓDIGO escaneado de la etiqueta (p. ej. "U-10001"), no el UUID
     * del agregado PhysicalUnit; ese código es justamente lo legible que ve el operador, así que se
     * muestra tal cual (no hay relación por id contra shipment_units).
     */
    private static function unitLabel(ScanEventRecord $record): string
    {
        return $record->unit_id;
    }

    /**
     * Etiqueta en inglés para un tipo de escaneo almacenado.
     */
    private static function typeLabel(string $value): string
    {
        return match ($value) {
            ScanType::Preparation->value => __('Preparation'),
            ScanType::Load->value => __('Load'),
            ScanType::Unload->value => __('Unload'),
            ScanType::ProofOfDelivery->value => __('Proof of delivery'),
            ScanType::NodeReception->value => __('Node reception'),
            ScanType::InTransit->value => __('In transit'),
            default => $value,
        };
    }
}
