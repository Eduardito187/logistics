<?php

declare(strict_types=1);

use Logistics\Notifications\Application\Command\ResetMessageTypeRule\ResetMessageTypeRule;
use Logistics\Notifications\Application\Command\ResetMessageTypeRule\ResetMessageTypeRuleHandler;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRule;
use Logistics\Notifications\Application\Command\SetMessageTypeRule\SetMessageTypeRuleHandler;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Tests\Unit\SpyCascadeRuleSettings;

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
