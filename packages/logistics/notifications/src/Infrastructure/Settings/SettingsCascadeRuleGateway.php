<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Settings;

use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Application\Rules\CascadeRuleCodec;
use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHistoryQuery;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Adaptador de las reglas de cascada contra el contexto Configuración (versionado con historial).
 *
 * Confina acá el acoplamiento entre contextos: habla con Configuración SOLO por los buses CQRS (sin
 * dependencia composer; autoload compartido del monolito modular). La lectura usa GetSettingQuery (valor
 * vigente a hoy) y la escritura SetSettingCommand (crea una versión nueva). Se mantiene además una
 * proyección de solo-estado en la tabla propia (`notification_message_type_rules`) para que el listado
 * tipado del admin siga funcionando; la FUENTE DE VERDAD que lee el motor es Configuración.
 *
 * Se bindea como `scoped` (una instancia por request/job): el memo de lectura vale dentro del request y
 * se reinicia entre requests, compatible con Octane.
 */
final class SettingsCascadeRuleGateway implements CascadeRuleSettings
{
    /**
     * Memo por request de los overrides decodificados, para no releer Configuración en cada resolución.
     *
     * @var array<string, CascadePolicy>|null
     */
    private ?array $memo = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly MessageTypeRuleRepository $projection,
    ) {}

    public function configured(): array
    {
        if ($this->memo === null) {
            $this->memo = CascadeRuleCodec::decode($this->currentValue());
        }

        return $this->memo;
    }

    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        return $this->configured()[$messageType->value] ?? null;
    }

    public function save(MessageType $messageType, CascadePolicy $policy, ?string $createdBy = null): void
    {
        $value = CascadeRuleCodec::upsert($this->currentValue() ?? [], $messageType, $policy);
        $this->commands->dispatch(new SetSettingCommand(CascadeRuleCodec::SETTING_KEY, $value, null, $createdBy));

        // Proyección de solo-estado para el listado del admin (el motor lee de Configuración).
        $this->projection->save($messageType, $policy);

        $this->memo = null;
    }

    public function remove(MessageType $messageType, ?string $createdBy = null): void
    {
        $value = CascadeRuleCodec::remove($this->currentValue() ?? [], $messageType);
        $this->commands->dispatch(new SetSettingCommand(CascadeRuleCodec::SETTING_KEY, $value, null, $createdBy));

        $this->projection->remove($messageType);

        $this->memo = null;
    }

    public function history(): array
    {
        /** @var list<array{id: string, key: string, version: int, value: array<string, mixed>, effective_from: string, created_at: string, created_by: string|null}> $versions */
        $versions = $this->queries->ask(new GetSettingHistoryQuery(CascadeRuleCodec::SETTING_KEY));

        $out = [];
        foreach ($versions as $version) {
            $rules = $version['value']['rules'] ?? null;

            $out[] = [
                'version' => $version['version'],
                'effective_from' => $version['effective_from'],
                'created_at' => $version['created_at'],
                'created_by' => $version['created_by'],
                'rule_count' => is_array($rules) ? count($rules) : 0,
            ];
        }

        return $out;
    }

    /**
     * Valor primitivo vigente de la clave de cascada, o null si todavía no se publicó ninguna versión.
     *
     * @return array<string, mixed>|null
     */
    private function currentValue(): ?array
    {
        /** @var mixed $value */
        $value = $this->queries->ask(new GetSettingQuery(CascadeRuleCodec::SETTING_KEY));

        if (!is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
