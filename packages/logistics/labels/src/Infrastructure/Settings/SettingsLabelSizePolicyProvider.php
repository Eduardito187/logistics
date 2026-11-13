<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Settings;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Logistics\Labels\Domain\Contract\LabelSizePolicyProvider;
use Logistics\Labels\Domain\ValueObject\LabelSizePolicy;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSizeRule;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lee la política de tamaño de etiqueta de la configuración versionada de `logistics/settings` (clave
 * `labels.size`) a través del QueryBus. Si no hay versión publicada para la fecha, cae al bloque `size` de
 * la config del package (`config/logistics-labels.php`). Así siempre hay una política con la que decidir el
 * tamaño (docs/architecture.md, principio 5). Mismo patrón que {@see SettingsLabelSymbologyProvider}.
 *
 * Forma del valor en settings/config:
 *   { default_profile, small_profile, small_max_longest_side_cm, small_max_weight_kg,
 *     rules: [{match, value, profile}] }
 */
final readonly class SettingsLabelSizePolicyProvider implements LabelSizePolicyProvider
{
    /** Clave namespaced de la política de tamaño en settings. */
    public const string SETTING_KEY = 'labels.size';

    public function __construct(
        private QueryBus $queryBus,
        private Repository $config,
    ) {}

    public function current(DateTimeImmutable $at): LabelSizePolicy
    {
        /** @var mixed $value */
        $value = $this->queryBus->ask(new GetSettingQuery(self::SETTING_KEY, $at->format(DATE_ATOM)));

        // Hay versión publicada en settings: manda su valor. Si no, cae a la config del package.
        $source = is_array($value) ? $value : $this->configSource();

        return $this->fromArray($source);
    }

    /**
     * @return array<string, mixed>
     */
    private function configSource(): array
    {
        $value = $this->config->get('logistics-labels.size');

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromArray(array $data): LabelSizePolicy
    {
        $default = LabelSizePolicy::default();

        $rules = [];
        $rawRules = $data['rules'] ?? [];
        if (is_array($rawRules)) {
            foreach ($rawRules as $raw) {
                if (is_array($raw)) {
                    $rule = LabelSizeRule::fromArray($raw);
                    if ($rule !== null) {
                        $rules[] = $rule;
                    }
                }
            }
        }

        return new LabelSizePolicy(
            defaultProfile: LabelSizeProfile::fromValue($data['default_profile'] ?? null),
            smallProfile: LabelSizeProfile::fromValue($data['small_profile'] ?? $default->smallProfile->value),
            rules: $rules,
            smallMaxLongestSideCm: self::nullableFloat($data['small_max_longest_side_cm'] ?? null, $default->smallMaxLongestSideCm),
            smallMaxWeightKg: self::nullableFloat($data['small_max_weight_kg'] ?? null, $default->smallMaxWeightKg),
        );
    }

    private static function nullableFloat(mixed $value, ?float $fallback): ?float
    {
        if ($value === null) {
            return $fallback;
        }

        return is_numeric($value) ? (float) $value : $fallback;
    }
}
