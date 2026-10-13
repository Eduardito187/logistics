<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\CreateHelper;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\EditHelper;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\ListHelpers;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\ViewHelper;

/**
 * Resource de administración de ayudantes de la flota.
 *
 * Filament usa HelperModel para el lado de LECTURA (tabla y formulario). El alta despacha RegisterHelper
 * por el CommandBus; la edición solo cambia la disponibilidad (SetHelperAvailability), el único comando
 * de modificación. Los documentos no se editan tras el alta (no hay comando para ello).
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class HelperResource extends Resource
{
    protected static ?string $model = HelperModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Helper');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Helpers');
    }

    public static function form(Schema $schema): Schema
    {
        // En edición solo la disponibilidad es mutable; el resto queda deshabilitado (no hay comando).
        $lockedOnEdit = static fn (string $operation): bool => $operation === 'edit';

        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(150)
                ->disabled($lockedOnEdit),

            TextInput::make('phone')
                ->label(__('Phone'))
                ->tel()
                ->maxLength(30)
                ->disabled($lockedOnEdit),

            Toggle::make('active')
                ->label(__('Active'))
                ->default(true),

            Repeater::make('documents')
                ->label(__('Documents'))
                ->disabled($lockedOnEdit)
                ->schema([
                    TextInput::make('type')
                        ->label(__('Document type'))
                        ->required()
                        ->maxLength(50),
                    DatePicker::make('expires_on')
                        ->label(__('Expires on'))
                        ->native(false)
                        ->required()
                        ->format('Y-m-d'),
                ])
                ->columns(2)
                ->default([]),
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
                TextColumn::make('phone')
                    ->label(__('Phone'))
                    ->placeholder('—'),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),
                TextColumn::make('documents')
                    ->label(__('Documents'))
                    ->formatStateUsing(static fn ($state): int => is_array($state) ? count($state) : 0),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('phone')->label(__('Phone'))->placeholder('—'),
            IconEntry::make('active')->label(__('Active'))->boolean(),
            RepeatableEntry::make('documents')
                ->label(__('Documents'))
                ->schema([
                    TextEntry::make('type')->label(__('Document type')),
                    TextEntry::make('expires_on')->label(__('Expires on'))->date('Y-m-d'),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListHelpers::route('/'),
            'create' => CreateHelper::route('/create'),
            'view' => ViewHelper::route('/{record}'),
            'edit' => EditHelper::route('/{record}/edit'),
        ];
    }
}
