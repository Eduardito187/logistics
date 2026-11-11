<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Configuration;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Fleet\Infrastructure\Settings\SettingsDocumentExpiryPolicy;
use Logistics\Settings\Presentation\Filament\Configuration\ConfigurationSection;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;

/**
 * Ventana de alerta de vencimiento de documentos de vehículos y personal (SOAT, inspección, licencias).
 * Precarga el valor EFECTIVO (setting o respaldo de config) desde la política del dominio.
 */
final class DocumentAlertsSection extends ConfigurationSection
{
    use ResolvesUserRoles;

    public static function slug(): string
    {
        return 'fleet-documents';
    }

    public static function tab(): string
    {
        return 'Fleet';
    }

    public static function tabSort(): int
    {
        return 20;
    }

    public static function label(): string
    {
        return 'Document alerts';
    }

    public static function sort(): int
    {
        return 10;
    }

    public static function canAccess(): bool
    {
        return self::authUserHasAnyRoleValue('admin', 'supervisor');
    }

    public function components(): array
    {
        return [
            Section::make(__('Expiry alerts'))
                ->collapsible()
                ->schema([
                    TextInput::make('days')
                        ->label(self::scoped('Alert window (days)'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(365)
                        ->required()
                        ->helperText(__('Documents expiring within this many days are flagged in the admin and notified.')),
                ]),
        ];
    }

    public function state(): array
    {
        return ['days' => app(DocumentExpiryPolicy::class)->alertWindowDays()];
    }

    public function save(array $data): void
    {
        $this->publish(SettingsDocumentExpiryPolicy::SETTING_KEY, ['days' => self::int($data, 'days', 30)]);
    }
}
