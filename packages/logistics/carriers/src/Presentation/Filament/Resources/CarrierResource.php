<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\Carriers\Application\Command\RateCarrier\RateCarrier;
use Logistics\Carriers\Application\Command\ReactivateCarrier\ReactivateCarrier;
use Logistics\Carriers\Application\Command\SuspendCarrier\SuspendCarrier;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages\CreateCarrier;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages\ListCarriers;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages\ViewCarrier;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de transportistas terceros.
 *
 * Nota de arquitectura: Filament se apoya en un modelo Eloquent para listar y mostrar; aquí se usa
 * CarrierRecord como proyección de lectura. Toda MUTACIÓN (alta, suspensión, calificación) se hace
 * despachando comandos al CommandBus CQRS, nunca escribiendo el record directamente. Por eso el
 * recurso no define edición: no existe un comando de edición genérica.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class CarrierResource extends Resource
{
    protected static ?string $model = CarrierRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Carriers');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-truck';
    }

    public static function getModelLabel(): string
    {
        return __('Carrier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Carriers');
    }

    /**
     * Representa la calificación promedio como estrellas llenas/vacías sobre 5, para una lectura humana.
     * Devuelve null cuando el transportista todavía no tiene calificaciones.
     */
    private static function stars(float | int | null $rating): ?string
    {
        if ($rating === null) {
            return null;
        }

        $rounded = (int) round((float) $rating);
        $rounded = max(0, min(5, $rounded));

        return str_repeat('★', $rounded).str_repeat('☆', 5 - $rounded);
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando RegisterCarrier.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('tax_id')
                ->label(__('Tax ID'))
                ->required()
                ->maxLength(64),
            Repeater::make('documents')
                ->label(__('Documents'))
                ->schema([
                    TextInput::make('type')
                        ->label(__('Type'))
                        ->required()
                        ->maxLength(64),
                    TextInput::make('reference')
                        ->label(__('Reference'))
                        ->required()
                        ->maxLength(255),
                ])
                ->columns(2)
                ->default([])
                ->addActionLabel(__('Add document')),
        ]);
    }

    /**
     * Vista de detalle (solo lectura): datos maestros del transportista más su reputación acumulada.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Carrier'))
                ->schema([
                    TextEntry::make('name')
                        ->label(__('Name')),
                    TextEntry::make('tax_id')
                        ->label(__('Tax ID')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => match ($state) {
                            'active' => 'success',
                            'suspended' => 'danger',
                            default => 'gray',
                        })
                        ->formatStateUsing(static fn (string $state): string => __(ucfirst($state))),
                ])
                ->columns(3),
            Section::make(__('Reputation'))
                ->schema([
                    TextEntry::make('rating')
                        ->label(__('Average rating'))
                        ->badge()
                        ->color(static fn (float | int | null $state): string => match (true) {
                            $state === null => 'gray',
                            $state >= 4 => 'success',
                            $state >= 3 => 'warning',
                            default => 'danger',
                        })
                        ->formatStateUsing(static fn (float | int | null $state): string => $state === null
                            ? __('No ratings yet')
                            : self::stars($state).' '.number_format((float) $state, 2))
                        ->placeholder(__('No ratings yet')),
                    TextEntry::make('rating_count')
                        ->label(__('Ratings count'))
                        ->numeric(),
                ])
                ->columns(2),
            Section::make(__('Documents'))
                ->schema([
                    RepeatableEntry::make('documents')
                        ->label(__('Documents'))
                        ->schema([
                            TextEntry::make('type')
                                ->label(__('Type')),
                            TextEntry::make('reference')
                                ->label(__('Reference')),
                        ])
                        ->columns(2),
                ]),
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
                TextColumn::make('tax_id')
                    ->label(__('Tax ID'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (string $state): string => __(ucfirst($state))),
                TextColumn::make('rating')
                    ->label(__('Avg. rating'))
                    ->badge()
                    ->color(static fn (float | int | null $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 4 => 'success',
                        $state >= 3 => 'warning',
                        default => 'danger',
                    })
                    ->formatStateUsing(static fn (float | int | null $state): string => $state === null
                        ? '—'
                        : self::stars($state).' '.number_format((float) $state, 2))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('rating_count')
                    ->label(__('Ratings'))
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'active' => __('Active'),
                        'suspended' => __('Suspended'),
                    ])
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                // Calificar: despacha RateCarrier al bus (1 a 5).
                Action::make('rate')
                    ->label(__('Rate'))
                    ->icon('heroicon-o-star')
                    ->modalHeading(__('Rate carrier'))
                    ->modalSubmitActionLabel(__('Submit rating'))
                    ->schema([
                        TextInput::make('rating')
                            ->label(__('Rating (1-5)'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5)
                            ->required(),
                    ])
                    ->action(static function (CarrierRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new RateCarrier(
                            carrierId: (string) $record->getKey(),
                            rating: (int) $data['rating'],
                        ));

                        Notification::make()
                            ->title(__('Rating submitted'))
                            ->success()
                            ->send();
                    }),
                // Suspender: despacha SuspendCarrier al bus. Solo visible si está activo.
                Action::make('suspend')
                    ->label(__('Suspend'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Suspend carrier'))
                    ->modalDescription(__('The carrier will stop receiving assignments until reactivated.'))
                    ->visible(static fn (CarrierRecord $record): bool => $record->status === 'active')
                    ->action(static function (CarrierRecord $record): void {
                        app(CommandBus::class)->dispatch(new SuspendCarrier(
                            carrierId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Carrier suspended'))
                            ->success()
                            ->send();
                    }),
                // Reactivar: despacha ReactivateCarrier al bus. Solo visible si está suspendido.
                Action::make('reactivate')
                    ->label(__('Reactivate'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Reactivate carrier'))
                    ->modalDescription(__('The carrier will be able to receive assignments again.'))
                    ->visible(static fn (CarrierRecord $record): bool => $record->status === 'suspended')
                    ->action(static function (CarrierRecord $record): void {
                        app(CommandBus::class)->dispatch(new ReactivateCarrier(
                            carrierId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Carrier reactivated'))
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
            'index' => ListCarriers::route('/'),
            'create' => CreateCarrier::route('/create'),
            'view' => ViewCarrier::route('/{record}'),
        ];
    }
}
