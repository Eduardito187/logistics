<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Settings;

use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Resuelve la ventana de alerta de vencimiento desde la configuración versionada (contexto settings),
 * vía el QueryBus, con respaldo por configuración (`fleet.expiry_alert_days`).
 *
 * El cruce entre contextos es por el bus (no por dependencia composer), igual que promise con capacity.
 * La clave guarda un valor primitivo `{"days": N}`; si no existe, es inválida o la lectura falla, se
 * usa el respaldo, de modo que el barrido nunca se cae por un problema de configuración.
 */
final readonly class SettingsDocumentExpiryPolicy implements DocumentExpiryPolicy
{
    /**
     * Clave de settings que guarda la ventana de alerta, como `{"days": N}`.
     */
    public const SETTING_KEY = 'fleet.document_expiry_alert_days';

    public function __construct(
        private QueryBus $queryBus,
        private int $defaultDays,
    ) {}

    public function alertWindowDays(): int
    {
        $days = $this->fromSettings();

        $resolved = $days ?? $this->defaultDays;

        return $resolved >= 0 ? $resolved : 0;
    }

    /**
     * Lee la ventana desde settings; null si no hay valor usable.
     */
    private function fromSettings(): ?int
    {
        try {
            /** @var mixed $value */
            $value = $this->queryBus->ask(new GetSettingQuery(self::SETTING_KEY));
        } catch (Throwable) {
            return null;
        }

        if (!is_array($value) || !isset($value['days'])) {
            return null;
        }

        $days = $value['days'];
        if (is_int($days)) {
            return $days;
        }

        // Tolera el número guardado como string (p. ej. desde un formulario del panel).
        if (is_string($days) && $days !== '' && ctype_digit($days)) {
            return (int) $days;
        }

        return null;
    }
}
