<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Rules;

use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Application\Contract\NotificationRulesProvider;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Binding de producción del NotificationRulesProvider: lee las reglas de cascada desde el contexto
 * Configuración (versionadas, con historial) a través del puerto CascadeRuleSettings, y cae a la cascada
 * por defecto del ADR 006 para los tipos que el operador no haya personalizado.
 *
 * Reemplaza al PersistedNotificationRulesProvider (tabla propia sin versionado): ahora la fuente de
 * verdad es Configuración, de modo que un cambio de regla queda versionado y auditado sin tocar código.
 */
final readonly class SettingsNotificationRulesProvider implements NotificationRulesProvider
{
    public function __construct(
        private CascadeRuleSettings $settings,
        private DefaultNotificationRulesProvider $defaults,
    ) {}

    public function policyFor(MessageType $messageType): CascadePolicy
    {
        return $this->settings->policyFor($messageType) ?? $this->defaults->policyFor($messageType);
    }
}
