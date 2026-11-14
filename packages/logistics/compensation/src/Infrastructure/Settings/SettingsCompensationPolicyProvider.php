<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Settings;

use Illuminate\Contracts\Config\Repository;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\ValueObject\CompensationPolicy;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lee la política de compensación de la configuración versionada de `logistics/settings` (clave
 * `compensation.policy`) por el QueryBus. Si no hay versión publicada, cae al bloque `policy` de la config
 * del package (`config/logistics-compensation.php`): así SIEMPRE hay con qué calcular, también en un docker
 * nuevo (memoria "funciona en docker nuevo"). Mismo patrón que SettingsLabelSizePolicyProvider en labels.
 *
 * La forma primitiva (array) es lo que se lee/cachea, nunca el objeto serializado (regla 7).
 */
final readonly class SettingsCompensationPolicyProvider implements CompensationPolicyProvider
{
    public const string SETTING_KEY = 'compensation.policy';

    public function __construct(
        private QueryBus $queryBus,
        private Repository $config,
    ) {}

    public function current(): CompensationPolicy
    {
        return CompensationPolicy::fromArray($this->source());
    }

    public function version(): int
    {
        $version = $this->source()['_version'] ?? null;

        return is_int($version) && $version > 0 ? $version : 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function source(): array
    {
        /** @var mixed $value */
        $value = $this->queryBus->ask(new GetSettingQuery(self::SETTING_KEY));
        if (is_array($value)) {
            return $value;
        }

        $fallback = $this->config->get('logistics-compensation.policy');

        return is_array($fallback) ? $fallback : [];
    }
}
