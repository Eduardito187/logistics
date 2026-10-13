<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Settings;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Logistics\Capacity\Domain\Contract\CapacityRulesetProvider;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lee las reglas de cupo de la configuración versionada de `logistics/settings` (clave
 * `capacity.dispatch_rules`) a través del QueryBus. Si todavía no hay versión publicada para la fecha
 * consultada, cae a los valores por defecto de la config del package (`config/logistics-capacity.php`),
 * de modo que el calendario siempre tiene reglas con qué trabajar (docs/architecture.md, principio 5).
 *
 * Nota de contextos: capacity lee el valor vigente de settings vía su API de lectura pública (QueryBus
 * + GetSettingQuery), no contra su persistencia; la referencia entre contextos es por su API, no por
 * objeto de dominio. El día que las reglas se editen siempre por el admin, esta implementación no cambia.
 */
final readonly class SettingsCapacityRulesetProvider implements CapacityRulesetProvider
{
    /** Clave namespaced de las reglas de cupo en settings. */
    public const string SETTING_KEY = 'capacity.dispatch_rules';

    public function __construct(
        private QueryBus $queryBus,
        private Repository $config,
    ) {}

    public function current(DateTimeImmutable $at): CapacityRuleset
    {
        /** @var mixed $value */
        $value = $this->queryBus->ask(new GetSettingQuery(self::SETTING_KEY, $at->format(DATE_ATOM)));

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return CapacityRuleset::fromArray($value);
        }

        return CapacityRuleset::fromArray($this->fallbackFromConfig());
    }

    /**
     * Reglas por defecto armadas desde la config del package, para cuando settings no tiene versión.
     * El cupo de entregas por franja usa `default_slot_deliveries` (el tope por franja), y peso/volumen
     * usan `default_totals`. Sin feriados ni scopes: el admin (o el seeder) publica la versión real.
     *
     * @return array<string, mixed>
     */
    private function fallbackFromConfig(): array
    {
        $base = 'logistics-capacity.dispatch_calendar.';

        $businessDays = $this->config->get($base.'business_days', [1, 2, 3, 4, 5, 6]);
        $windows = $this->config->get($base.'windows', []);
        $cutoff = $this->config->get($base.'order_cutoff', '12:00');
        $horizon = $this->config->get($base.'horizon_days', 14);
        $slotDeliveries = $this->config->get($base.'default_slot_deliveries', 40);
        $weight = $this->config->get('logistics-capacity.default_totals.weight', 8000);
        $volume = $this->config->get('logistics-capacity.default_totals.volume', 40);

        return [
            'service_days' => is_array($businessDays) ? array_values($businessDays) : [1, 2, 3, 4, 5, 6],
            'order_cutoff' => is_string($cutoff) ? $cutoff : '12:00',
            'horizon_days' => is_numeric($horizon) ? (int) $horizon : 14,
            'holidays' => [],
            'windows' => is_array($windows) ? array_values($windows) : [],
            'windows_by_weekday' => [],
            'totals' => [
                'deliveries' => is_numeric($slotDeliveries) ? (float) $slotDeliveries : 40.0,
                'weight' => is_numeric($weight) ? (float) $weight : 8000.0,
                'volume' => is_numeric($volume) ? (float) $volume : 40.0,
            ],
            'totals_by_slot' => [],
            'scopes' => [],
        ];
    }
}
