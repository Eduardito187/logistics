<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\PermissionRecord;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages\CreatePermission;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages\ListPermissions;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages\ViewPermission;

/**
 * Admin del catálogo de permisos de RBAC.
 *
 * Nota de arquitectura: Filament se apoya en PermissionRecord solo como proyección de lectura para
 * listar y mostrar. El alta se hace despachando el comando RegisterPermission al CommandBus CQRS
 * (ver CreatePermission), nunca escribiendo el record. Este recurso es de SOLO LECTURA + ALTA: la clave
 * de un permiso es un contrato estable y no se edita, por eso no hay página ni acción de edición.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class PermissionResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = PermissionRecord::class;

    protected static ?string $recordTitleAttribute = 'key';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 14;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-key';
    }

    public static function getModelLabel(): string
    {
        return __('Permission');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Permissions');
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando RegisterPermission; el grupo y la acción
     * se derivan de la clave en el dominio, por eso no se piden aquí.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')
                ->label(__('Key'))
                ->helperText(__('Stable "group.action" key in lowercase, e.g. shipments.read.'))
                ->required()
                ->maxLength(255),
            TextInput::make('description')
                ->label(__('Description'))
                ->maxLength(255),
            Toggle::make('is_system')
                ->label(__('System permission'))
                ->helperText(__('System permissions are seeded by the platform and protected.'))
                ->default(false),
        ]);
    }

    /**
     * Vista de detalle (solo lectura) de un permiso del catálogo.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Permission'))
                ->schema([
                    TextEntry::make('key')
                        ->label(__('Key'))
                        ->copyable(),
                    TextEntry::make('group')
                        ->label(__('Group')),
                    TextEntry::make('action')
                        ->label(__('Action')),
                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->placeholder('—'),
                    IconEntry::make('is_system')
                        ->label(__('System'))
                        ->boolean(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // El id (UUID) nunca se muestra: la URL ya lo lleva. Se identifica el permiso por su key.
                TextColumn::make('key')
                    ->label(__('Key'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('group')
                    ->label(__('Group'))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('action')
                    ->label(__('Action'))
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->placeholder('—')
                    ->limit(60)
                    ->toggleable(),
                IconColumn::make('is_system')
                    ->label(__('System'))
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('key');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'view' => ViewPermission::route('/{record}'),
        ];
    }
}
