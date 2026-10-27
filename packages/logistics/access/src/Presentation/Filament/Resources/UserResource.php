<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\Access\Application\Command\AssignRoleToUser\AssignRoleToUser;
use Logistics\Access\Application\Command\ChangeUserPassword\ChangeUserPassword;
use Logistics\Access\Application\Command\ReactivateUser\ReactivateUser;
use Logistics\Access\Application\Command\RemoveRoleFromUser\RemoveRoleFromUser;
use Logistics\Access\Application\Command\SuspendUser\SuspendUser;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\RoleRecord;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\UserRecord;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Access\Presentation\Filament\Resources\UserResource\Pages\CreateUser;
use Logistics\Access\Presentation\Filament\Resources\UserResource\Pages\ListUsers;
use Logistics\Access\Presentation\Filament\Resources\UserResource\Pages\ViewUser;
use Logistics\Access\Presentation\Filament\Support\AuditRecorder;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de usuarios de la plataforma.
 *
 * Nota de arquitectura: Filament se apoya en UserRecord solo como proyección de lectura. El alta y toda
 * mutación (suspender, reactivar, (des)asignar roles, cambiar contraseña) se hacen despachando comandos
 * al CommandBus CQRS, nunca escribiendo el record. El hash de la contraseña NUNCA se muestra ni se edita.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class UserResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = UserRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 10;
    }

    public static function getNavigationIcon(): string
    {
        return 'ds-users';
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    /**
     * Opciones reales de roles para los selects (clave => nombre), ordenadas y buscables.
     *
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        /** @var array<string, string> $options */
        $options = RoleRecord::query()
            ->orderBy('name')
            ->pluck('name', 'key')
            ->all();

        return $options;
    }

    /**
     * Color del badge según el estado del usuario.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'suspended' => 'danger',
            'invited' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando RegisterUser; la contraseña viaja en claro
     * al dominio, que la hashea (nunca se persiste el texto plano ni se vuelve a mostrar).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label(__('Email'))
                ->email()
                ->required()
                ->maxLength(255),
            TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->revealable()
                ->required()
                ->minLength(8)
                ->maxLength(255)
                ->dehydrated(),
            Select::make('status')
                ->label(__('Status'))
                ->native(false)
                ->options([
                    'active' => __('Active'),
                    'invited' => __('Invited'),
                ])
                ->default('active')
                ->required(),
            Select::make('role_keys')
                ->label(__('Roles'))
                ->multiple()
                ->native(false)
                ->searchable()
                ->preload()
                ->options(static fn (): array => self::roleOptions())
                ->default([]),
        ]);
    }

    /**
     * Vista de detalle (solo lectura). Nunca expone el hash de la contraseña.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('User'))
                ->schema([
                    TextEntry::make('name')
                        ->label(__('Name')),
                    TextEntry::make('email')
                        ->label(__('Email'))
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(static fn (string $state): string => __(ucfirst($state))),
                    TextEntry::make('role_keys')
                        ->label(__('Roles'))
                        ->badge()
                        ->placeholder(__('No roles')),
                    TextEntry::make('last_login_at')
                        ->label(__('Last login'))
                        ->dateTime()
                        ->placeholder(__('Never')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // El id (UUID) nunca se muestra: la URL ya lo lleva. Se identifica al usuario por name/email.
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => __(ucfirst($state)))
                    ->sortable(),
                TextColumn::make('role_keys')
                    ->label(__('Roles'))
                    ->badge()
                    ->state(static fn (UserRecord $record): int => count($record->role_keys))
                    ->formatStateUsing(static fn (int $state): string => (string) $state)
                    ->color('gray'),
                TextColumn::make('last_login_at')
                    ->label(__('Last login'))
                    ->dateTime()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'active' => __('Active'),
                        'suspended' => __('Suspended'),
                        'invited' => __('Invited'),
                    ])
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                // Suspender: despacha SuspendUser al bus. Visible solo si no está ya suspendido.
                Action::make('suspend')
                    ->label(__('Suspend'))
                    ->icon('ds-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Suspend user'))
                    ->modalDescription(__('The user will not be able to sign in until reactivated.'))
                    ->visible(static fn (UserRecord $record): bool => $record->status !== 'suspended')
                    ->action(static function (UserRecord $record): void {
                        app(CommandBus::class)->dispatch(new SuspendUser(
                            userId: (string) $record->getKey(),
                        ));

                        AuditRecorder::record('user.suspended', 'access.user', (string) $record->getKey());

                        Notification::make()
                            ->title(__('User suspended'))
                            ->success()
                            ->send();
                    }),
                // Reactivar: despacha ReactivateUser al bus. Visible solo si está suspendido.
                Action::make('reactivate')
                    ->label(__('Reactivate'))
                    ->icon('ds-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Reactivate user'))
                    ->modalDescription(__('The user will be able to sign in again.'))
                    ->visible(static fn (UserRecord $record): bool => $record->status === 'suspended')
                    ->action(static function (UserRecord $record): void {
                        app(CommandBus::class)->dispatch(new ReactivateUser(
                            userId: (string) $record->getKey(),
                        ));

                        AuditRecorder::record('user.reactivated', 'access.user', (string) $record->getKey());

                        Notification::make()
                            ->title(__('User reactivated'))
                            ->success()
                            ->send();
                    }),
                // Asignar/quitar roles: se calcula el delta contra los roles actuales y se despacha un
                // comando por cada diferencia (AssignRoleToUser / RemoveRoleFromUser), nunca un write directo.
                Action::make('roles')
                    ->label(__('Manage roles'))
                    ->icon('ds-user-group')
                    ->modalHeading(__('Manage user roles'))
                    ->modalSubmitActionLabel(__('Save'))
                    ->fillForm(static fn (UserRecord $record): array => [
                        'role_keys' => $record->role_keys,
                    ])
                    ->schema([
                        Select::make('role_keys')
                            ->label(__('Roles'))
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options(static fn (): array => self::roleOptions())
                            ->default([]),
                    ])
                    ->action(static function (UserRecord $record, array $data): void {
                        /** @var list<string> $current */
                        $current = array_values($record->role_keys);
                        /** @var list<string> $next */
                        $next = array_values(is_array($data['role_keys'] ?? null) ? $data['role_keys'] : []);

                        $userId = (string) $record->getKey();
                        $bus = app(CommandBus::class);

                        $added = array_values(array_diff($next, $current));
                        $removed = array_values(array_diff($current, $next));

                        foreach ($added as $roleKey) {
                            $bus->dispatch(new AssignRoleToUser(
                                userId: $userId,
                                roleKey: (string) $roleKey,
                            ));
                        }

                        foreach ($removed as $roleKey) {
                            $bus->dispatch(new RemoveRoleFromUser(
                                userId: $userId,
                                roleKey: (string) $roleKey,
                            ));
                        }

                        // Solo se audita si hubo cambios reales (delta no vacío).
                        if ($added !== [] || $removed !== []) {
                            AuditRecorder::record('user.roles_changed', 'access.user', $userId, [
                                'added' => $added,
                                'removed' => $removed,
                            ]);
                        }

                        Notification::make()
                            ->title(__('Roles updated'))
                            ->success()
                            ->send();
                    }),
                // Cambiar contraseña: despacha ChangeUserPassword al bus (texto plano, hasheado en el dominio).
                Action::make('changePassword')
                    ->label(__('Change password'))
                    ->icon('ds-lock-closed')
                    ->modalHeading(__('Change user password'))
                    ->modalSubmitActionLabel(__('Change password'))
                    ->schema([
                        TextInput::make('password')
                            ->label(__('New password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->maxLength(255),
                    ])
                    ->action(static function (UserRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new ChangeUserPassword(
                            userId: (string) $record->getKey(),
                            newPlainPassword: (string) $data['password'],
                        ));

                        // La contraseña nunca se audita: solo el hecho de que cambió y para quién.
                        AuditRecorder::record('user.password_changed', 'access.user', (string) $record->getKey());

                        Notification::make()
                            ->title(__('Password changed'))
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
