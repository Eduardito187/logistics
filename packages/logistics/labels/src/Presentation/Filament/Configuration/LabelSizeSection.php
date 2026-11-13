<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Configuration;

use DateTimeImmutable;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Logistics\Labels\Domain\Contract\LabelSizePolicyProvider;
use Logistics\Labels\Domain\ValueObject\LabelSizeMatch;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSizeRule;
use Logistics\Labels\Infrastructure\Settings\SettingsLabelSizePolicyProvider;
use Logistics\Settings\Presentation\Filament\Configuration\ConfigurationSection;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;

/**
 * Tamaño/perfil de las etiquetas (roadmap 03 — Etiquetas Lite/chicas): el perfil por defecto, el perfil
 * «chico», los umbrales de medidas y las reglas por SKU. El tamaño es PRESENTACIÓN: la misma etiqueta se
 * reimprime en cualquier perfil sin cambiar su id ni el QR firmado. Texto visible en inglés (regla 5).
 */
final class LabelSizeSection extends ConfigurationSection
{
    use ResolvesUserRoles;

    public static function slug(): string
    {
        return 'labels-size';
    }

    public static function tab(): string
    {
        return 'Labels';
    }

    public static function tabSort(): int
    {
        return 30;
    }

    public static function sort(): int
    {
        return 40;
    }

    public static function label(): string
    {
        return 'Label sizes';
    }

    public static function canAccess(): bool
    {
        return self::authUserHasAnyRoleValue('admin', 'supervisor');
    }

    /**
     * Opciones de perfil de tamaño, con sus medidas legibles.
     *
     * @return array<string, string>
     */
    public static function profileOptions(): array
    {
        $options = [];
        foreach (LabelSizeProfile::cases() as $profile) {
            $options[$profile->value] = __($profile->label());
        }

        return $options;
    }

    /**
     * Opciones del tipo de match de una regla por SKU.
     *
     * @return array<string, string>
     */
    public static function matchOptions(): array
    {
        $options = [];
        foreach (LabelSizeMatch::cases() as $match) {
            $options[$match->value] = __($match->label());
        }

        return $options;
    }

    public function components(): array
    {
        return [
            Section::make(__('Default size'))
                ->description(__('Labels print in Standard unless a rule or the product measures pick a smaller profile. Size is only how a label is printed: it never changes the label id or the signed QR.'))
                ->collapsible()
                ->schema([
                    Select::make('default_profile')
                        ->label(self::scoped('Default profile'))
                        ->native(false)
                        ->options(self::profileOptions())
                        ->required(),
                    Select::make('small_profile')
                        ->label(self::scoped('Small-product profile'))
                        ->native(false)
                        ->options(self::profileOptions())
                        ->required()
                        ->helperText(__('Profile used for products matched as small by a rule or by their measures.')),
                ]),
            Section::make(__('Small-product thresholds'))
                ->description(__('A product is small when its longest side is at or below the maximum and, when known, its weight is at or below the maximum. Products without measures stay on the default profile.'))
                ->collapsible()
                ->schema([
                    TextInput::make('small_max_longest_side_cm')
                        ->label(self::scoped('Max longest side (cm)'))
                        ->numeric()
                        ->minValue(0)
                        ->helperText(__('Leave empty to disable the measure-based rule.')),
                    TextInput::make('small_max_weight_kg')
                        ->label(self::scoped('Max weight (kg)'))
                        ->numeric()
                        ->minValue(0)
                        ->helperText(__('Leave empty to ignore weight.')),
                ]),
            Section::make(__('Size rules by SKU'))
                ->description(__('Evaluated in order; the first matching rule wins. Rules depend on the product, never on the seller.'))
                ->collapsible()
                ->schema([
                    Repeater::make('rules')
                        ->label(__('Rules'))
                        ->schema([
                            Select::make('match')
                                ->label(__('Match'))
                                ->native(false)
                                ->options(self::matchOptions())
                                ->default(LabelSizeMatch::SkuExact->value)
                                ->required(),
                            TextInput::make('value')
                                ->label(__('SKU or prefix'))
                                ->placeholder('SAMS-A15')
                                ->required()
                                ->maxLength(64),
                            Select::make('profile')
                                ->label(__('Profile'))
                                ->native(false)
                                ->options(self::profileOptions())
                                ->default(LabelSizeProfile::RECOMMENDED_SMALL->value)
                                ->required(),
                        ])
                        ->columns(3)
                        ->reorderable()
                        ->addActionLabel(__('Add rule'))
                        ->columnSpanFull(),
                ]),
        ];
    }

    public function state(): array
    {
        $policy = app(LabelSizePolicyProvider::class)->current(new DateTimeImmutable);

        return [
            'default_profile' => $policy->defaultProfile->value,
            'small_profile' => $policy->smallProfile->value,
            'small_max_longest_side_cm' => $policy->smallMaxLongestSideCm,
            'small_max_weight_kg' => $policy->smallMaxWeightKg,
            'rules' => array_map(
                static fn (LabelSizeRule $rule): array => $rule->toArray(),
                $policy->rules,
            ),
        ];
    }

    public function save(array $data): void
    {
        $rules = [];
        $rawRules = $data['rules'] ?? [];
        if (is_array($rawRules)) {
            foreach ($rawRules as $raw) {
                if (is_array($raw)) {
                    $rule = LabelSizeRule::fromArray($raw);
                    if ($rule !== null) {
                        $rules[] = $rule->toArray();
                    }
                }
            }
        }

        $this->publish(SettingsLabelSizePolicyProvider::SETTING_KEY, [
            'default_profile' => LabelSizeProfile::fromValue($data['default_profile'] ?? null)->value,
            'small_profile' => LabelSizeProfile::fromValue($data['small_profile'] ?? null)->value,
            'small_max_longest_side_cm' => self::nullableNumber($data['small_max_longest_side_cm'] ?? null),
            'small_max_weight_kg' => self::nullableNumber($data['small_max_weight_kg'] ?? null),
            'rules' => $rules,
        ]);
    }

    private static function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
