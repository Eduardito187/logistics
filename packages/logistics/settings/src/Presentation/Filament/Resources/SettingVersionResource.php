<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Settings\Infrastructure\Persistence\Eloquent\SettingVersionRecord;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages\CreateSettingVersion;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages\ListSettingVersions;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages\ViewSettingVersion;

/**
 * Admin Filament de la configuración versionada (tabla settings_versions).
 *
 * Cada fila es una versión INMUTABLE de una clave namespaced (p.ej. `coverage.default_service_days`):
 * nunca se actualiza ni se borra, solo se inserta una nueva versión. Por eso el admin NO expone editar
 * ni eliminar; "cambiar" una regla significa publicar una nueva versión, lo que hace la página Create
 * despachando el comando SetSetting al bus CQRS (el dominio asigna id, número de versión y fecha).
 *
 * El listado, agrupado por clave y ordenado por versión descendente, ES el historial de versiones; la
 * vista de detalle muestra el valor JSON completo de una versión concreta. Todo texto visible va en
 * inglés (regla 5). Grupo de navegación "Platform" junto al outbox y demás plomería de plataforma.
 *
 * El integrador registra este Resource en el panel de Filament (discoverResources / resources()); el
 * package está en dont-discover, así que no se autodescubre.
 *
 * @extends resource<SettingVersionRecord>
 */
final class SettingVersionResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = SettingVersionRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 52;

    protected static ?string $recordTitleAttribute = 'key';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('Setting versions');
    }

    public static function getModelLabel(): string
    {
        return __('Setting version');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Setting versions');
    }

    /**
     * Las versiones son inmutables: una vez publicadas no se editan a mano.
     */
    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    /**
     * Las versiones son inmutables: nunca se borran (historial de auditoría permanente).
     */
    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')
                ->label(__('Key'))
                ->required()
                ->maxLength(255)
                ->helperText(__('Namespaced key with at least one dot, e.g. coverage.default_service_days.'))
                ->rule('regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'),
            Textarea::make('value')
                ->label(__('Value (JSON)'))
                ->required()
                ->rows(10)
                ->helperText(__('JSON object stored as the setting value, e.g. {"days": 3, "cutoff": "14:00"}.'))
                ->rule('json'),
            DateTimePicker::make('effective_from')
                ->label(__('Effective from'))
                ->seconds(false)
                ->native(false)
                ->helperText(__('When this version starts to apply. Leave empty to apply immediately.')),
            TextInput::make('created_by')
                ->label(__('Created by'))
                ->maxLength(255)
                ->helperText(__('Who is publishing this change, for auditing.')),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('key')
                ->label(__('Key'))
                ->copyable(),
            TextEntry::make('version')
                ->label(__('Version'))
                ->badge()
                ->color('info'),
            TextEntry::make('value')
                ->label(__('Value'))
                ->formatStateUsing(static fn (mixed $state): string => self::prettyJson($state)),
            TextEntry::make('effective_from')
                ->label(__('Effective from'))
                ->dateTime(),
            TextEntry::make('created_at')
                ->label(__('Created at'))
                ->dateTime(),
            TextEntry::make('created_by')
                ->label(__('Created by'))
                ->placeholder(__('Unknown')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label(__('Key'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('version')
                    ->label(__('Version'))
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->label(__('Effective from'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_by')
                    ->label(__('Created by'))
                    ->placeholder(__('Unknown'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('key')
                    ->label(__('Key'))
                    ->native(false)
                    ->searchable()
                    ->options(self::keyOptions()),
            ])
            ->groups([
                Group::make('key')
                    ->label(__('Key'))
                    ->collapsible(),
            ])
            ->defaultGroup('key')
            ->defaultSort('version', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSettingVersions::route('/'),
            'create' => CreateSettingVersion::route('/create'),
            'view' => ViewSettingVersion::route('/{record}'),
        ];
    }

    /**
     * Opciones del filtro de clave: claves distintas reales existentes en la tabla.
     *
     * @return array<string, string>
     */
    private static function keyOptions(): array
    {
        /** @var list<string> $keys */
        $keys = SettingVersionRecord::query()
            ->distinct()
            ->orderBy('key')
            ->pluck('key')
            ->all();

        return array_combine($keys, $keys);
    }

    /**
     * Formatea un valor jsonb como JSON legible para la vista de detalle.
     */
    private static function prettyJson(mixed $state): string
    {
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }
}
