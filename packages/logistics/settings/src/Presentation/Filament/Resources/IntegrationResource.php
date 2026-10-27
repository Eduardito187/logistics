<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Settings\Infrastructure\Persistence\Eloquent\SettingVersionRecord;
use Logistics\Settings\Presentation\Filament\Resources\IntegrationResource\Pages\ManageIntegrations;

/**
 * Panel de Integraciones del admin: un switch on/off por integración + sus credenciales/parámetros,
 * guardados (secretos cifrados) y leídos en runtime, en vez de depender solo de .env.
 *
 * No es un CRUD: su única página es una pantalla de gestión (ManageIntegrations) que lee el estado vía
 * IntegrationConfig y publica cambios despachando SetSetting al bus CQRS (igual que el calendario de
 * cupos). Se declara como Resource para que lo autodescubra el `discoverResources` del panel apuntado a
 * este package (packages/logistics/settings/src/Presentation/Filament) y así NO haya que tocar el
 * AdminPanelProvider (archivo compartido). El `$model` solo satisface al Resource; la página no hace
 * Eloquent sobre él. Texto visible en inglés (regla 5); comentarios en español (regla 6).
 *
 * @extends resource<SettingVersionRecord>
 */
final class IntegrationResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = SettingVersionRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'ds-puzzle-piece';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('Integrations');
    }

    public static function getModelLabel(): string
    {
        return __('Integration');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Integrations');
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

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ManageIntegrations::route('/'),
        ];
    }
}
