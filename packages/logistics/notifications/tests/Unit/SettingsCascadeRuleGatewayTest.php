<?php

declare(strict_types=1);

use Logistics\Notifications\Application\Rules\CascadeRuleCodec;
use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Settings\SettingsCascadeRuleGateway;
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

/**
 * Proyección de solo-estado en memoria (la tabla propia del listado del admin).
 */
final class InMemoryMessageTypeRuleRepository implements MessageTypeRuleRepository
{
    /** @var array<string, CascadePolicy> */
    public array $policies = [];

    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        return $this->policies[$messageType->value] ?? null;
    }

    public function save(MessageType $messageType, CascadePolicy $policy): void
    {
        $this->policies[$messageType->value] = $policy;
    }

    public function remove(MessageType $messageType): void
    {
        unset($this->policies[$messageType->value]);
    }

    public function all(): array
    {
        return $this->policies;
    }
}

function makeGateway(FakeSettingsBus $bus, InMemoryMessageTypeRuleRepository $projection): SettingsCascadeRuleGateway
{
    return new SettingsCascadeRuleGateway($bus, $bus, $projection);
}

it('publica una versión nueva en Configuración al guardar un override', function (): void {
    $bus = new FakeSettingsBus;
    $projection = new InMemoryMessageTypeRuleRepository;
    $gateway = makeGateway($bus, $projection);

    $gateway->save(
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Email, Channel::Sms], EscalateCondition::OnFailure),
        'tester',
    );

    expect($bus->versions)->toHaveCount(1)
        ->and($bus->versions[0]['value']['rules']['EN_ROUTE'])->toBe([
            'channels' => ['EMAIL', 'SMS'],
            'escalate_when' => 'ON_FAILURE',
        ])
        // La fuente de verdad es Configuración; el override se lee de vuelta ya decodificado.
        ->and($gateway->policyFor(MessageType::EnRoute)?->channels)->toBe([Channel::Email, Channel::Sms])
        // Y se mantiene la proyección de solo-estado para el listado del admin.
        ->and($projection->policyFor(MessageType::EnRoute)?->channels)->toBe([Channel::Email, Channel::Sms]);
});

it('no pisa el historial: cada cambio agrega una versión', function (): void {
    $bus = new FakeSettingsBus;
    $gateway = makeGateway($bus, new InMemoryMessageTypeRuleRepository);

    $gateway->save(MessageType::EnRoute, CascadePolicy::of([Channel::Email]), 'a');
    $gateway->save(MessageType::Delivered, CascadePolicy::of([Channel::Sms]), 'b');
    $gateway->remove(MessageType::EnRoute, 'c');

    $history = $gateway->history();

    expect($bus->versions)->toHaveCount(3)
        ->and($history)->toHaveCount(3)
        // Historial de la más nueva a la más vieja.
        ->and($history[0]['version'])->toBe(3)
        ->and($history[0]['created_by'])->toBe('c')
        // Tras el remove de EN_ROUTE, solo queda DELIVERED personalizado.
        ->and($history[0]['rule_count'])->toBe(1)
        ->and($history[2]['version'])->toBe(1);
});

it('devuelve null para un tipo sin override (el llamador cae al default)', function (): void {
    $gateway = makeGateway(new FakeSettingsBus, new InMemoryMessageTypeRuleRepository);

    expect($gateway->policyFor(MessageType::Arriving))->toBeNull()
        ->and($gateway->configured())->toBe([]);
});

it('quitar un override lo saca de la configuración vigente', function (): void {
    $bus = new FakeSettingsBus;
    $projection = new InMemoryMessageTypeRuleRepository;
    $gateway = makeGateway($bus, $projection);

    $gateway->save(MessageType::EnRoute, CascadePolicy::of([Channel::Email]), 'tester');
    expect($gateway->policyFor(MessageType::EnRoute))->not->toBeNull();

    $gateway->remove(MessageType::EnRoute, 'tester');

    expect($gateway->policyFor(MessageType::EnRoute))->toBeNull()
        ->and($projection->policyFor(MessageType::EnRoute))->toBeNull();
});
