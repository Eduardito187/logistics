<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Settings;

use Logistics\Fleet\Domain\Contract\ShiftRulesPolicy;
use Logistics\Fleet\Domain\ValueObject\ShiftRules;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Resuelve las reglas de turnos y descansos desde la configuración versionada (contexto settings), vía
 * el QueryBus, con respaldo por el valor por defecto (que el provider arma desde config/fleet.php).
 *
 * El cruce entre contextos es por el bus (no por dependencia composer), igual que DocumentExpiryPolicy.
 * La clave guarda un valor primitivo con los cuatro umbrales en minutos; si no existe, es inválida o la
 * lectura falla, se usa el respaldo, de modo que armar una tripulación nunca se cae por configuración.
 */
final readonly class SettingsShiftRulesPolicy implements ShiftRulesPolicy
{
    /**
     * Clave de settings con las reglas de turnos, como
     * `{"max_daily_minutes": N, "earliest_start_minute": N, "latest_end_minute": N, "min_rest_minutes": N}`.
     */
    public const SETTING_KEY = 'fleet.crew_shift_rules';

    public function __construct(
        private QueryBus $queryBus,
        private ShiftRules $default,
    ) {}

    public function rules(): ShiftRules
    {
        return $this->fromSettings() ?? $this->default;
    }

    /**
     * Lee las reglas desde settings; null si no hay un valor usable o si no superan la validación del VO.
     */
    private function fromSettings(): ?ShiftRules
    {
        try {
            /** @var mixed $value */
            $value = $this->queryBus->ask(new GetSettingQuery(self::SETTING_KEY));
        } catch (Throwable) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        $max = $this->intOrNull($value['max_daily_minutes'] ?? null);
        $earliest = $this->intOrNull($value['earliest_start_minute'] ?? null);
        $latest = $this->intOrNull($value['latest_end_minute'] ?? null);
        $rest = $this->intOrNull($value['min_rest_minutes'] ?? null);

        if ($max === null || $earliest === null || $latest === null || $rest === null) {
            return null;
        }

        try {
            return ShiftRules::of($max, $earliest, $latest, $rest);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normaliza a entero un valor que puede venir como int o como string de dígitos (desde un formulario).
     */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
