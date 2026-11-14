<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Settings\SettingsCascadeRuleGateway;
use Logistics\Notifications\Tests\Unit\FakeSettingsBus;
use Logistics\Notifications\Tests\Unit\InMemoryMessageTypeRuleRepository;

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
