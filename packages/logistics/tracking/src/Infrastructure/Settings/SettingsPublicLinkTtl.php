<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Settings;

use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Contract\PublicLinkTtl;
use Throwable;

/**
 * Lee el TTL del enlace público desde el contexto Configuración (versionado) por el QueryBus, sin
 * dependencia composer con ese package (mismo patrón que SettingsCascadeRuleGateway de notifications).
 *
 * Tolerante: si la clave no existe, tiene forma inesperada o la consulta falla, cae al default (72 h), así
 * la página pública funciona en un docker nuevo sin configurar nada. Se bindea `scoped`: memoiza el valor
 * dentro del request/job (la página pública se pollea) y se reinicia entre requests (Octane-safe).
 */
final class SettingsPublicLinkTtl implements PublicLinkTtl
{
    public const string SETTING_KEY = 'tracking.public_link_ttl_hours';

    private ?int $memo = null;

    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function hours(): int
    {
        return $this->memo ??= $this->resolve();
    }

    private function resolve(): int
    {
        try {
            /** @var mixed $value */
            $value = $this->queries->ask(new GetSettingQuery(self::SETTING_KEY));
        } catch (Throwable) {
            return self::DEFAULT_HOURS;
        }

        if (!is_array($value)) {
            return self::DEFAULT_HOURS;
        }

        $hours = $value['hours'] ?? null;

        return is_numeric($hours) ? (int) $hours : self::DEFAULT_HOURS;
    }
}
