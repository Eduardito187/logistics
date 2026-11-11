<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Configuration;

use DateTimeImmutable;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Logistics\Labels\Domain\Contract\LabelSymbologyProvider;
use Logistics\Labels\Domain\ValueObject\LabelSymbology;
use Logistics\Labels\Infrastructure\Settings\SettingsLabelSymbologyProvider;
use Logistics\Settings\Presentation\Filament\Configuration\ConfigurationSection;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;

/**
 * Formato (simbología) de las etiquetas: QR firmado, código de barras Zebra (Code128) o ambos.
 * El código único de la etiqueta va siempre, sea cual sea la simbología.
 */
final class LabelFormatSection extends ConfigurationSection
{
    use ResolvesUserRoles;

    public static function slug(): string
    {
        return 'labels-format';
    }

    public static function tab(): string
    {
        return 'Labels';
    }

    public static function tabSort(): int
    {
        return 30;
    }

    public static function label(): string
    {
        return 'Label format';
    }

    public static function canAccess(): bool
    {
        return self::authUserHasAnyRoleValue('admin', 'supervisor');
    }

    public function components(): array
    {
        return [
            Section::make(__('Printed symbology'))
                ->collapsible()
                ->schema([
                    Select::make('symbology')
                        ->label(self::scoped('Symbology'))
                        ->native(false)
                        ->options([
                            LabelSymbology::Qr->value => __('QR only (signed, scanned by the app offline)'),
                            LabelSymbology::Barcode->value => __('Zebra barcode only (Code128, read by a 1D laser scanner)'),
                            LabelSymbology::Both->value => __('Both (QR + Zebra)'),
                        ])
                        ->required()
                        ->helperText(__('New labels and previews will use the selected symbology. Reprint existing labels to apply it to them.')),
                ]),
        ];
    }

    public function state(): array
    {
        return ['symbology' => app(LabelSymbologyProvider::class)->current(new DateTimeImmutable)->value];
    }

    public function save(array $data): void
    {
        $this->publish(SettingsLabelSymbologyProvider::SETTING_KEY, [
            SettingsLabelSymbologyProvider::SETTING_FIELD => LabelSymbology::fromValue($data['symbology'] ?? null)->value,
        ]);
    }
}
