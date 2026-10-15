<?php

declare(strict_types=1);

use Logistics\Notifications\Application\Command\ResetMessageTypeRule\ResetMessageTypeRule;
use Logistics\Notifications\Application\Command\ResetMessageTypeRule\ResetMessageTypeRuleHandler;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRule;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRuleHandler;
use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Doble de las reglas de cascada (puerto CascadeRuleSettings) que registra lo que se le pide guardar o
 * quitar, para verificar que los handlers construyen los VO correctos y delegan en Configuración.
 */
final class SpyCascadeRuleSettings implements CascadeRuleSettings
{
    /** @var list<array{type: MessageType, policy: CascadePolicy, by: string|null}> */
    public array $saved = [];

    /** @var list<array{type: MessageType, by: string|null}> */
    public array $removed = [];

    public function configured(): array
    {
        return [];
    }

    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        return null;
    }

    public function save(MessageType $messageType, CascadePolicy $policy, ?string $createdBy = null): void
    {
        $this->saved[] = ['type' => $messageType, 'policy' => $policy, 'by' => $createdBy];
    }

    public function remove(MessageType $messageType, ?string $createdBy = null): void
    {
        $this->removed[] = ['type' => $messageType, 'by' => $createdBy];
    }

    public function history(): array
    {
        return [];
    }
}

it('SetMessageTypeRuleHandler construye la política y la publica en Configuración', function (): void {
    $spy = new SpyCascadeRuleSettings;
    $handler = new SetMessageTypeRuleHandler($spy);

    $handler->handle(new SetMessageTypeRule(
        messageType: 'EN_ROUTE',
        channels: ['EMAIL', 'SMS'],
        escalateWhen: 'ON_FAILURE',
        createdBy: 'tester',
    ));

    expect($spy->saved)->toHaveCount(1)
        ->and($spy->saved[0]['type'])->toBe(MessageType::EnRoute)
        ->and($spy->saved[0]['policy']->channels)->toBe([Channel::Email, Channel::Sms])
        ->and($spy->saved[0]['policy']->escalateWhen)->toBe(EscalateCondition::OnFailure)
        ->and($spy->saved[0]['by'])->toBe('tester');
});

it('SetMessageTypeRuleHandler rechaza canales inválidos (valida el VO)', function (): void {
    $handler = new SetMessageTypeRuleHandler(new SpyCascadeRuleSettings);

    $handler->handle(new SetMessageTypeRule('EN_ROUTE', ['CANAL_FALSO'], 'NO_OPEN'));
})->throws(ValueError::class);

it('ResetMessageTypeRuleHandler quita el override del tipo en Configuración', function (): void {
    $spy = new SpyCascadeRuleSettings;
    $handler = new ResetMessageTypeRuleHandler($spy);

    $handler->handle(new ResetMessageTypeRule('DELIVERED', 'tester'));

    expect($spy->removed)->toHaveCount(1)
        ->and($spy->removed[0]['type'])->toBe(MessageType::Delivered)
        ->and($spy->removed[0]['by'])->toBe('tester');
});
