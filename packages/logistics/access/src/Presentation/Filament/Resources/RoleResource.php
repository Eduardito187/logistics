<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Logistics\Access\Application\Command\DeleteRole\DeleteRole;
use Logistics\Access\Application\Command\RenameRole\RenameRole;
use Logistics\Access\Application\Command\SetRolePermissions\SetRolePermissions;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\PermissionRecord;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\RoleRecord;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages\CreateRole;
use Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages\ListRoles;
use Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages\ViewRole;
use Logistics\Access\Presentation\Filament\Support\AuditRecorder;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de roles de RBAC.
 *
 * Nota de arquitectura: Filament se apoya en RoleRecord solo como proyección de lectura. El alta y toda
 * mutación (renombrar, fijar permisos, borrar) se hacen despachando comandos al CommandBus CQRS, nunca
 * escribiendo el record. Por eso no hay página de edición genérica: cada cambio es una acción
 * transaccional explícita. Los roles de sistema (is_system) no se pueden borrar.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class RoleResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = RoleRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 12;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user-group';
    }

    public static function getModelLabel(): string
    {
        return __('Role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Roles');
    }

    /**
     * Opciones reales de permisos para los selects (clave => clave), ordenadas y buscables.
     *
     * @return array<string, string>
     */
    private static function permissionOptions(): array
    {
        /** @var array<string, string> $options */
        $options = PermissionRecord::query()
            ->orderBy('key')
            ->pluck('key', 'key')
            ->all();

        return $options;
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando DefineRole.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')
                ->label(__('Key'))
                ->helperText(__('Stable slug for the role, e.g. warehouse-supervisor.'))
                ->required()
                ->maxLength(255),
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('description')
                ->label(__('Description'))
                ->maxLength(255),
            Select::make('permission_keys')
                ->label(__('Permissions'))
                ->helperText(__('Permissions granted by this role.'))
                ->multiple()
                ->native(false)
                ->searchable()
                ->preload()
                ->options(static fn (): array => self::permissionOptions())
                ->default([]),
        ]);
    }

    /**
     * Vista de detalle (solo lectura) de un rol y sus permisos.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Role'))
                ->schema([
                    TextEntry::make('name')
                        ->label(__('Name')),
                    TextEntry::make('key')
                        ->label(__('Key'))
                        ->copyable(),
                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->placeholder('—'),
                    IconEntry::make('is_system')
                        ->label(__('System'))
                        ->boolean(),
                    TextEntry::make('permission_keys')
                        ->label(__('Permissions'))
                        ->badge()
                        ->placeholder(__('No permissions')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // El id (UUID) nunca se muestra: la URL ya lo lleva. Se identifica el rol por key/name.
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label(__('Key'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('permission_keys')
                    ->label(__('Permissions'))
                    ->badge()
                    ->state(static fn (RoleRecord $record): int => count($record->permission_keys))
                    ->formatStateUsing(static fn (int $state): string => (string) $state)
                    ->color('gray'),
                IconColumn::make('is_system')
                    ->label(__('System'))
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ViewAction::make(),
                // Renombrar: despacha RenameRole al bus (nombre y/o descripción visibles).
                Action::make('rename')
                    ->label(__('Rename'))
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(__('Rename role'))
                    ->modalSubmitActionLabel(__('Save'))
                    ->fillForm(static fn (RoleRecord $record): array => [
                        'name' => $record->name,
                        'description' => $record->description,
                    ])
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('description')
                            ->label(__('Description'))
                            ->maxLength(255),
                    ])
                    ->action(static function (RoleRecord $record, array $data): void {
                        $description = isset($data['description']) && $data['description'] !== ''
                            ? (string) $data['description']
                            : null;

                        app(CommandBus::class)->dispatch(new RenameRole(
                            roleId: (string) $record->getKey(),
                            name: (string) $data['name'],
                            description: $description,
                        ));

                        AuditRecorder::record('role.renamed', 'access.role', (string) $record->getKey(), [
                            'name' => (string) $data['name'],
                            'description' => $description,
                        ]);

                        Notification::make()
                            ->title(__('Role renamed'))
                            ->success()
                            ->send();
                    }),
                // Editar permisos: reemplaza el conjunto completo vía SetRolePermissions.
                Action::make('editPermissions')
                    ->label(__('Edit permissions'))
                    ->icon('heroicon-o-key')
                    ->modalHeading(__('Edit role permissions'))
                    ->modalSubmitActionLabel(__('Save'))
                    ->fillForm(static fn (RoleRecord $record): array => [
                        'permission_keys' => $record->permission_keys,
                    ])
                    ->schema([
                        Select::make('permission_keys')
                            ->label(__('Permissions'))
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options(static fn (): array => self::permissionOptions())
                            ->default([]),
                    ])
                    ->action(static function (RoleRecord $record, array $data): void {
                        /** @var list<string> $keys */
                        $keys = array_values(is_array($data['permission_keys'] ?? null) ? $data['permission_keys'] : []);

                        app(CommandBus::class)->dispatch(new SetRolePermissions(
                            roleId: (string) $record->getKey(),
                            permissionKeys: $keys,
                        ));

                        AuditRecorder::record('role.permissions_changed', 'access.role', (string) $record->getKey(), [
                            'permissions' => $keys,
                        ]);

                        Notification::make()
                            ->title(__('Permissions updated'))
                            ->success()
                            ->send();
                    }),
                // Borrar: despacha DeleteRole al bus. Oculto para roles de sistema (protegidos).
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete role'))
                    ->modalDescription(__('The role will be removed. This cannot be undone.'))
                    ->visible(static fn (RoleRecord $record): bool => !$record->is_system)
                    ->action(static function (RoleRecord $record): void {
                        app(CommandBus::class)->dispatch(new DeleteRole(
                            roleId: (string) $record->getKey(),
                        ));

                        AuditRecorder::record('role.deleted', 'access.role', (string) $record->getKey(), [
                            'key' => $record->key,
                            'name' => $record->name,
                        ]);

                        Notification::make()
                            ->title(__('Role deleted'))
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
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
        ];
    }
}
