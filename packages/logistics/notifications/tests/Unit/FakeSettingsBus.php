<?php

declare(strict_types=1);

namespace Logistics\Notifications\Tests\Unit;

use Logistics\Notifications\Application\Rules\CascadeRuleCodec;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHistoryQuery;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Store de configuración en memoria que simula el contexto Configuración detrás de los buses CQRS:
 * guarda las versiones de la clave `notifications.cascade_rules` y sirve el valor vigente y el historial.
 * Permite probar el gateway end-to-end (lectura/escritura versionada) sin base ni contenedor.
 */
final class FakeSettingsBus implements CommandBus, QueryBus
{
    /** @var list<array{version: int, value: array<string, mixed>, created_by: string|null}> */
    public array $versions = [];

    public function ask(Query $query): mixed
    {
        if ($query instanceof GetSettingQuery) {
            $last = end($this->versions);

            return $last === false ? null : $last['value'];
        }

        if ($query instanceof GetSettingHistoryQuery) {
            $out = [];
            foreach (array_reverse($this->versions) as $v) {
                $out[] = [
                    'id' => 'id-'.$v['version'],
                    'key' => CascadeRuleCodec::SETTING_KEY,
                    'version' => $v['version'],
                    'value' => $v['value'],
                    'effective_from' => '2026-01-01T00:00:00+00:00',
                    'created_at' => '2026-01-01T00:00:00+00:00',
                    'created_by' => $v['created_by'],
                ];
            }

            return $out;
        }

        return null;
    }

    public function dispatch(Command $command): void
    {
        if (!$command instanceof SetSettingCommand) {
            return;
        }

        $this->versions[] = [
            'version' => count($this->versions) + 1,
            'value' => $command->value,
            'created_by' => $command->createdBy,
        ];
    }
}
