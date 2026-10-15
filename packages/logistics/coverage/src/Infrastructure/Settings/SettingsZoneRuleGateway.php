<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Settings;

use Logistics\Coverage\Application\Contract\ZoneRuleSettings;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Adaptador de la regla de cobertura por zona contra el contexto Configuración (versionado con
 * historial). Confina acá el acoplamiento entre contextos: habla con Configuración SOLO por los buses
 * CQRS (sin dependencia composer; autoload compartido del monolito modular), igual que fleet con el
 * vencimiento de documentos y notifications con las reglas de cascada.
 *
 * - La lectura usa GetSettingQuery (valor vigente a una fecha) y es TOLERANTE: si la clave no existe,
 *   el valor no es usable o la consulta falla, devuelve null, de modo que resolver una zona nunca se
 *   cae por un problema de configuración.
 * - La escritura usa SetSettingCommand (crea la próxima versión). Se publica desde los handlers de
 *   escritura de la zona, dentro de su transacción; si la publicación de la versión falla, debe
 *   propagar para que el cambio no quede a medias.
 *
 * La clave es namespaced por zona: `coverage.zone_rules.{zoneId}`.
 */
final readonly class SettingsZoneRuleGateway implements ZoneRuleSettings
{
    /**
     * Prefijo de la clave de settings que guarda la regla de cada zona.
     */
    public const string KEY_PREFIX = 'coverage.zone_rules.';

    public function __construct(
        private CommandBus $commands,
        private QueryBus $queries,
    ) {}

    public function publish(string $zoneId, ZoneRules $rules, ?string $createdBy = null): void
    {
        $this->commands->dispatch(new SetSettingCommand(
            self::keyFor($zoneId),
            $rules->toArray(),
            null,
            $createdBy,
        ));
    }

    public function effectiveFor(string $zoneId, ?string $at = null): ?ZoneRules
    {
        try {
            /** @var mixed $value */
            $value = $this->queries->ask(new GetSettingQuery(self::keyFor($zoneId), $at));
        } catch (Throwable) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        try {
            return ZoneRules::fromArray($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Clave de settings para una zona.
     *
     * Las claves de Configuración exigen segmentos en minúsculas que empiezan por letra y solo usan
     * [a-z0-9_]; un UUID (con guiones y empezando por dígito) no encaja como segmento, así que se
     * codifica: prefijo 'z' y los guiones a guion bajo. La codificación es estable y reversible por
     * zona, de modo que lectura y escritura comparten exactamente la misma clave.
     */
    public static function keyFor(string $zoneId): string
    {
        return self::KEY_PREFIX.'z'.str_replace('-', '_', strtolower($zoneId));
    }
}
