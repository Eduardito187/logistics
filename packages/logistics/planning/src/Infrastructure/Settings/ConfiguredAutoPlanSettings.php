<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Settings;

use Illuminate\Contracts\Config\Repository as Config;
use Logistics\Planning\Domain\Contract\AutoPlanSettingsProvider;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;

/**
 * Resuelve los parámetros del auto-planificador desde la configuración del package
 * (`config/logistics-planning.php`, bajo `auto_planner`), con override puntual por comando/API. Es el
 * stub previsto por el patrón de capacity: deja el punto de extensión funcional hasta que
 * `logistics/settings` sirva estas reglas versionadas; ese día se cambia solo el binding del provider,
 * sin tocar el dominio ni el runner que lo consumen.
 */
final readonly class ConfiguredAutoPlanSettings implements AutoPlanSettingsProvider
{
    public function __construct(private Config $config) {}

    public function parametersFor(PlanDate $date, PlanStrategy $strategy, array $overrides): AutoPlanParameters
    {
        return new AutoPlanParameters(
            strategy: $strategy,
            departAt: $this->resolveDepartAt($date, $overrides),
            defaultWeightKgPerPackage: $this->resolveFloat($overrides, 'default_weight_kg_per_package', 10.0),
            defaultVolumeM3PerPackage: $this->resolveFloat($overrides, 'default_volume_m3_per_package', 0.05),
            capacitySlackRatio: $this->resolveSlack($overrides),
            originNodeId: $this->resolveOrigin($overrides),
        );
    }

    /**
     * Hora de salida: override explícito, o el día del plan a la hora por defecto configurada.
     *
     * @param array<string, mixed> $overrides
     */
    private function resolveDepartAt(PlanDate $date, array $overrides): string
    {
        $override = $overrides['depart_at'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $time = $this->config->get('logistics-planning.auto_planner.default_depart_time');
        $departTime = is_string($time) && trim($time) !== '' ? trim($time) : '07:30';

        return $date->toString().'T'.$departTime.':00';
    }

    /**
     * Holgura de capacidad (0..1): override, o el valor configurado, o 10 % por defecto.
     *
     * @param array<string, mixed> $overrides
     */
    private function resolveSlack(array $overrides): float
    {
        $override = $overrides['capacity_slack'] ?? null;
        if (is_numeric($override)) {
            return (float) $override;
        }

        $configured = $this->config->get('logistics-planning.auto_planner.capacity_slack');

        return is_numeric($configured) ? (float) $configured : 0.1;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolveFloat(array $overrides, string $key, float $default): float
    {
        $override = $overrides[$key] ?? null;
        if (is_numeric($override)) {
            return (float) $override;
        }

        $configured = $this->config->get("logistics-planning.auto_planner.{$key}");

        return is_numeric($configured) ? (float) $configured : $default;
    }

    /**
     * Nodo CD de origen: override, o el configurado, o null.
     *
     * @param array<string, mixed> $overrides
     */
    private function resolveOrigin(array $overrides): ?string
    {
        $override = $overrides['origin_node_id'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $configured = $this->config->get('logistics-planning.auto_planner.origin_node_id');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }
}
