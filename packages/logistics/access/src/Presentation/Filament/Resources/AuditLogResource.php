<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\AuditEntryRecord;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Access\Presentation\Filament\Resources\AuditLogResource\Pages\ListAuditEntries;
use Logistics\Access\Presentation\Filament\Resources\AuditLogResource\Pages\ViewAuditEntry;

/**
 * Bitácora de auditoría de accesos: registro de SOLO LECTURA de las acciones sensibles del módulo de
 * acceso (altas, suspensiones, cambios de roles/permisos, emisión/revocación de tokens).
 *
 * Nota de arquitectura: Filament se apoya en AuditEntryRecord solo como proyección de lectura. Las
 * entradas son inmutables y append-only: este recurso no permite crear, editar ni borrar (lo escribe el
 * handler RecordAuditEntry al despacharse cada acción sensible). Es admin-only, como el resto del módulo.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class AuditLogResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = AuditEntryRecord::class;

    protected static ?int $navigationSort = 18;

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    public static function getModelLabel(): string
    {
        return __('Audit entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audit log');
    }

    /**
     * La bitácora no se crea a mano: la escriben los handlers. Se oculta la acción de alta.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Vocabulario controlado de acciones auditadas: clave estable => etiqueta visible en inglés.
     *
     * @return array<string, string>
     */
    private static function actionOptions(): array
    {
        return [
            'user.registered' => __('User registered'),
            'user.suspended' => __('User suspended'),
            'user.reactivated' => __('User reactivated'),
            'user.roles_changed' => __('User roles changed'),
            'user.password_changed' => __('User password changed'),
            'role.defined' => __('Role defined'),
            'role.renamed' => __('Role renamed'),
            'role.permissions_changed' => __('Role permissions changed'),
            'role.deleted' => __('Role deleted'),
            'permission.registered' => __('Permission registered'),
            'token.issued' => __('Token issued'),
            'token.rotated' => __('Token secret rotated'),
            'token.revoked' => __('Token revoked'),
            'token.permissions_changed' => __('Token permissions changed'),
        ];
    }

    /**
     * Tipos de sujeto auditables: clave estable => etiqueta visible en inglés.
     *
     * @return array<string, string>
     */
    private static function subjectTypeOptions(): array
    {
        return [
            'access.user' => __('User'),
            'access.role' => __('Role'),
            'access.permission' => __('Permission'),
            'access.integration_token' => __('Integration token'),
        ];
    }

    /**
     * Etiqueta visible de una acción; si no está en el vocabulario, muestra la clave tal cual.
     */
    private static function actionLabel(string $action): string
    {
        return self::actionOptions()[$action] ?? $action;
    }

    /**
     * Etiqueta visible de un tipo de sujeto; si no está en el vocabulario, muestra la clave tal cual.
     */
    private static function subjectTypeLabel(string $subjectType): string
    {
        return self::subjectTypeOptions()[$subjectType] ?? $subjectType;
    }

    /**
     * Vista de detalle (solo lectura) de una entrada de auditoría, con su contexto en JSON legible.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Audit entry'))
                ->schema([
                    TextEntry::make('occurred_at')
                        ->label(__('When'))
                        ->dateTime(),
                    TextEntry::make('actor_label')
                        ->label(__('Actor')),
                    // El actor_id (UUID) no se muestra: actor_label ya expone el nombre/email legible del autor.
                    TextEntry::make('action')
                        ->label(__('Action'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => self::actionLabel($state)),
                    TextEntry::make('subject_type')
                        ->label(__('Subject'))
                        ->badge()
                        ->color('gray')
                        ->formatStateUsing(static fn (string $state): string => self::subjectTypeLabel($state)),
                    // El subject_id (UUID) no se muestra: basta el tipo de sujeto legible (subject_type).
                    TextEntry::make('ip_address')
                        ->label(__('IP address'))
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('context')
                        ->label(__('Context'))
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->formatStateUsing(static fn (mixed $state): string => self::formatContext($state)),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('When'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('actor_label')
                    ->label(__('Actor'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('action')
                    ->label(__('Action'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::actionLabel($state))
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label(__('Subject'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => self::subjectTypeLabel($state))
                    ->sortable(),
                // El subject_id (UUID) no se muestra: basta el tipo de sujeto legible (subject_type).
                TextColumn::make('ip_address')
                    ->label(__('IP address'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('action')
                    ->label(__('Action'))
                    ->options(static fn (): array => self::actionOptions())
                    ->native(false),
                SelectFilter::make('subject_type')
                    ->label(__('Subject'))
                    ->options(static fn (): array => self::subjectTypeOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAuditEntries::route('/'),
            'view' => ViewAuditEntry::route('/{record}'),
        ];
    }

    /**
     * Formatea el contexto (array de primitivos) como JSON legible para la vista de detalle.
     */
    private static function formatContext(mixed $state): string
    {
        if (is_string($state)) {
            return $state;
        }

        if (is_array($state)) {
            if ($state === []) {
                return '—';
            }

            return (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return '—';
    }
}
