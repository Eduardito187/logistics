<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
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
use Logistics\Access\Application\Command\RevokeIntegrationToken\RevokeIntegrationToken;
use Logistics\Access\Application\Command\SetTokenPermissions\SetTokenPermissions;
use Logistics\Access\Application\ReadModel\IssuedTokenView;
use Logistics\Access\Application\Service\IntegrationTokenIssuer;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\IntegrationTokenRecord;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\PermissionRecord;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages\CreateIntegrationToken;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages\ListIntegrationTokens;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages\ViewIntegrationToken;
use Logistics\Access\Presentation\Filament\Support\AuditRecorder;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de tokens de integración (credenciales máquina-a-máquina).
 *
 * Nota de arquitectura: Filament se apoya en IntegrationTokenRecord solo como proyección de lectura.
 * El alta y la rotación NO pasan por el CommandBus sino por el servicio IntegrationTokenIssuer, porque el
 * secreto en claro solo puede revelarse una única vez y nunca se recupera (solo se persiste su hash). El
 * resto de mutaciones (revocar, fijar permisos) sí se despachan al bus CQRS. El hash NUNCA se muestra.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class IntegrationTokenResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = IntegrationTokenRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 16;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-command-line';
    }

    public static function getModelLabel(): string
    {
        return __('Integration token');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Integration tokens');
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
     * Color del badge según el estado del token.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'revoked' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Revela el token en claro UNA sola vez tras emitirlo o rotarlo: notificación persistente con el
     * valor completo y un botón para copiarlo al portapapeles. La plataforma no lo vuelve a mostrar.
     */
    public static function revealToken(IssuedTokenView $view): void
    {
        Notification::make()
            ->title(__('Copy this token now'))
            ->body(__('This is the only time the token is shown. It cannot be retrieved later.').' '.$view->plainToken)
            ->persistent()
            ->warning()
            ->actions([
                Action::make('copy')
                    ->label(__('Copy token'))
                    ->icon('heroicon-o-clipboard')
                    ->extraAttributes([
                        'x-on:click' => "window.navigator.clipboard.writeText('".addslashes($view->plainToken)."')",
                    ]),
            ])
            ->send();
    }

    /**
     * El formulario de alta vive en la página CreateIntegrationToken (usa el Issuer, no un comando), pero
     * Filament exige declarar el esquema aquí para reutilizarlo. El permiso es un select con datos reales.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            Select::make('permission_keys')
                ->label(__('Permissions'))
                ->helperText(__('Permissions this token is allowed to use.'))
                ->multiple()
                ->native(false)
                ->searchable()
                ->preload()
                ->options(static fn (): array => self::permissionOptions())
                ->default([])
                ->required(),
            DateTimePicker::make('expires_at')
                ->label(__('Expires at'))
                ->native(false)
                ->helperText(__('Leave empty for a token that never expires.')),
        ]);
    }

    /**
     * Vista de detalle (solo lectura). Nunca expone el hash del secreto.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Integration token'))
                ->schema([
                    TextEntry::make('name')
                        ->label(__('Name')),
                    TextEntry::make('token_prefix')
                        ->label(__('Token prefix'))
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(static fn (string $state): string => __(ucfirst($state))),
                    TextEntry::make('permission_keys')
                        ->label(__('Permissions'))
                        ->badge()
                        ->placeholder(__('No permissions')),
                    TextEntry::make('expires_at')
                        ->label(__('Expires at'))
                        ->dateTime()
                        ->placeholder(__('Never')),
                    TextEntry::make('last_used_at')
                        ->label(__('Last used'))
                        ->dateTime()
                        ->placeholder(__('Never')),
                    TextEntry::make('created_by')
                        ->label(__('Created by'))
                        ->placeholder('—'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // El id (UUID) nunca se muestra: la URL ya lo lleva. Se identifica el token por name/token_prefix.
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('token_prefix')
                    ->label(__('Token prefix'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => __(ucfirst($state)))
                    ->sortable(),
                TextColumn::make('permission_keys')
                    ->label(__('Permissions'))
                    ->badge()
                    ->state(static fn (IntegrationTokenRecord $record): int => count($record->permission_keys))
                    ->formatStateUsing(static fn (int $state): string => (string) $state)
                    ->color('gray'),
                TextColumn::make('expires_at')
                    ->label(__('Expires at'))
                    ->dateTime()
                    ->placeholder(__('Never'))
                    ->sortable(),
                TextColumn::make('last_used_at')
                    ->label(__('Last used'))
                    ->dateTime()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'active' => __('Active'),
                        'revoked' => __('Revoked'),
                    ])
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                // Editar permisos: reemplaza el conjunto completo vía SetTokenPermissions.
                Action::make('editPermissions')
                    ->label(__('Edit permissions'))
                    ->icon('heroicon-o-key')
                    ->modalHeading(__('Edit token permissions'))
                    ->modalSubmitActionLabel(__('Save'))
                    ->visible(static fn (IntegrationTokenRecord $record): bool => $record->status === 'active')
                    ->fillForm(static fn (IntegrationTokenRecord $record): array => [
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
                            ->default([])
                            ->required(),
                    ])
                    ->action(static function (IntegrationTokenRecord $record, array $data): void {
                        /** @var list<string> $keys */
                        $keys = array_values(is_array($data['permission_keys'] ?? null) ? $data['permission_keys'] : []);

                        app(CommandBus::class)->dispatch(new SetTokenPermissions(
                            tokenId: (string) $record->getKey(),
                            permissionKeys: $keys,
                        ));

                        AuditRecorder::record('token.permissions_changed', 'access.integration_token', (string) $record->getKey(), [
                            'permissions' => $keys,
                        ]);

                        Notification::make()
                            ->title(__('Permissions updated'))
                            ->success()
                            ->send();
                    }),
                // Rotar secreto: el Issuer genera un secreto nuevo y revela el token en claro una vez más.
                Action::make('rotate')
                    ->label(__('Rotate secret'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading(__('Rotate token secret'))
                    ->modalDescription(__('The current secret stops working immediately and a new one is revealed once.'))
                    ->visible(static fn (IntegrationTokenRecord $record): bool => $record->status === 'active')
                    ->action(static function (IntegrationTokenRecord $record): void {
                        $view = app(IntegrationTokenIssuer::class)->rotate(
                            IntegrationTokenId::fromString((string) $record->getKey()),
                        );

                        AuditRecorder::record('token.rotated', 'access.integration_token', (string) $record->getKey());

                        self::revealToken($view);
                    }),
                // Revocar: despacha RevokeIntegrationToken al bus. Irreversible.
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Revoke integration token'))
                    ->modalDescription(__('The token stops authenticating requests permanently. This cannot be undone.'))
                    ->visible(static fn (IntegrationTokenRecord $record): bool => $record->status === 'active')
                    ->action(static function (IntegrationTokenRecord $record): void {
                        app(CommandBus::class)->dispatch(new RevokeIntegrationToken(
                            tokenId: (string) $record->getKey(),
                        ));

                        AuditRecorder::record('token.revoked', 'access.integration_token', (string) $record->getKey());

                        Notification::make()
                            ->title(__('Token revoked'))
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
            'index' => ListIntegrationTokens::route('/'),
            'create' => CreateIntegrationToken::route('/create'),
            'view' => ViewIntegrationToken::route('/{record}'),
        ];
    }
}
