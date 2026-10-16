<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Domain\ValueObject\SurveyReason;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\SatisfactionSurveyRecord;
use Logistics\Tracking\Presentation\Filament\Resources\SatisfactionSurveyResource\Pages\ListSatisfactionSurveys;
use Logistics\Tracking\Presentation\Filament\Resources\SatisfactionSurveyResource\Pages\ViewSatisfactionSurvey;

/**
 * Admin de las respuestas de la encuesta de satisfacción post-entrega (K-06).
 *
 * Es de SOLO LECTURA: las respuestas las crea el cliente desde la página pública, nunca el back office
 * (crear/editar/borrar deshabilitados). Muestra la calificación como estrellas, el motivo como badge, el
 * comentario del cliente y la referencia humana del envío (external_id + cliente) vía la relación
 * Eloquent, no el UUID crudo. La cabecera del listado trae el widget de NPS/promedio/volumen.
 *
 * Todo el texto visible va en inglés (regla 5). El integrador descubre este Resource por el
 * discoverResources del package; no se autodescubre (dont-discover).
 *
 * @extends resource<SatisfactionSurveyRecord>
 */
final class SatisfactionSurveyResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = SatisfactionSurveyRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-star';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationSort(): int
    {
        return 41;
    }

    public static function getNavigationLabel(): string
    {
        return __('Satisfaction surveys');
    }

    public static function getModelLabel(): string
    {
        return __('Satisfaction survey');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Satisfaction surveys');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Eager-load del envío para que las columnas shipment.* no disparen N+1 en el listado.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('shipment');
    }

    /**
     * Título humano del registro: la referencia del envío calificado, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof SatisfactionSurveyRecord) {
            return null;
        }

        return self::shipmentReference($record);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Rating'))
                ->schema([
                    TextEntry::make('rating')
                        ->label(__('Rating'))
                        ->formatStateUsing(fn (int $state): string => self::stars($state)),
                    TextEntry::make('reason')
                        ->label(__('Reason'))
                        ->badge()
                        ->placeholder('—')
                        ->formatStateUsing(fn (?string $state): string => self::reasonLabel($state)),
                    TextEntry::make('comment')
                        ->label(__('Comment'))
                        ->placeholder(__('No comment'))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Shipment'))
                ->schema([
                    TextEntry::make('shipment.external_id')
                        ->label(__('Shipment reference'))
                        ->placeholder(__('Unknown shipment')),
                    TextEntry::make('shipment.customer_name')
                        ->label(__('Customer'))
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label(__('Submitted at'))
                        ->dateTime(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shipment.external_id')
                    ->label(__('Shipment'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (SatisfactionSurveyRecord $record): ?string => $record->shipment?->customer_name)
                    ->placeholder(__('Unknown shipment')),
                TextColumn::make('rating')
                    ->label(__('Rating'))
                    ->formatStateUsing(fn (int $state): string => self::stars($state))
                    ->color(fn (int $state): string => self::ratingColor($state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => self::reasonLabel($state)),
                TextColumn::make('comment')
                    ->label(__('Comment'))
                    ->limit(60)
                    ->placeholder(__('No comment'))
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label(__('Submitted'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->label(__('Rating'))
                    ->native(false)
                    ->options([
                        '5' => self::stars(5),
                        '4' => self::stars(4),
                        '3' => self::stars(3),
                        '2' => self::stars(2),
                        '1' => self::stars(1),
                    ]),
                SelectFilter::make('reason')
                    ->label(__('Reason'))
                    ->native(false)
                    ->options(self::reasonOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSatisfactionSurveys::route('/'),
            'view' => ViewSatisfactionSurvey::route('/{record}'),
        ];
    }

    /**
     * Referencia humana del envío para mostrar en vez del UUID. Si falta el envío, texto neutro.
     */
    private static function shipmentReference(SatisfactionSurveyRecord $record): string
    {
        $shipment = $record->shipment;
        if ($shipment instanceof ShipmentRecord) {
            return "{$shipment->external_id} — {$shipment->customer_name}";
        }

        return __('Unknown shipment');
    }

    /**
     * Representación de la calificación como estrellas llenas/vacías (1-5).
     */
    private static function stars(int $rating): string
    {
        $rating = max(1, min(5, $rating));

        return str_repeat('★', $rating).str_repeat('☆', 5 - $rating);
    }

    /**
     * Color del badge por calificación: verde (5), ámbar (4), rojo (<=3).
     */
    private static function ratingColor(int $rating): string
    {
        return match (true) {
            $rating >= 5 => 'success',
            $rating === 4 => 'warning',
            default => 'danger',
        };
    }

    /**
     * Etiqueta en inglés del motivo, o guion si no hay.
     */
    private static function reasonLabel(?string $reason): string
    {
        $case = $reason !== null ? SurveyReason::tryFrom($reason) : null;

        return match ($case) {
            SurveyReason::Late => __('Late delivery'),
            SurveyReason::Damaged => __('Damaged product'),
            SurveyReason::Incomplete => __('Incomplete order'),
            SurveyReason::Courier => __('Courier issue'),
            SurveyReason::Other => __('Other'),
            null => '—',
        };
    }

    /**
     * Opciones del filtro de motivo: valor del enum => etiqueta legible.
     *
     * @return array<string, string>
     */
    private static function reasonOptions(): array
    {
        $options = [];
        foreach (SurveyReason::cases() as $reason) {
            $options[$reason->value] = self::reasonLabel($reason->value);
        }

        return $options;
    }
}
