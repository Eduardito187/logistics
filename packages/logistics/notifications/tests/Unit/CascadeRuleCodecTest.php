<?php

declare(strict_types=1);

use Logistics\Notifications\Application\Rules\CascadeRuleCodec;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * El códec traduce entre la forma primitiva de la clave de Configuración `notifications.cascade_rules`
 * y las políticas de cascada del dominio. Se prueba el round-trip y, sobre todo, la TOLERANCIA ante un
 * valor editado a mano (tipos desconocidos, canales inválidos, estructura rota).
 */
it('decodifica un override válido a su política por tipo', function (): void {
    $value = [
        'rules' => [
            'EN_ROUTE' => ['channels' => ['EMAIL', 'SMS'], 'escalate_when' => 'ON_FAILURE'],
        ],
    ];

    $policies = CascadeRuleCodec::decode($value);

    expect($policies)->toHaveKey('EN_ROUTE')
        ->and($policies['EN_ROUTE']->channels)->toBe([Channel::Email, Channel::Sms])
        ->and($policies['EN_ROUTE']->escalateWhen)->toBe(EscalateCondition::OnFailure);
});

it('devuelve un mapa vacío ante null o sin rules (todo usa el default)', function (): void {
    expect(CascadeRuleCodec::decode(null))->toBe([])
        ->and(CascadeRuleCodec::decode([]))->toBe([])
        ->and(CascadeRuleCodec::decode(['rules' => 'no-es-arreglo']))->toBe([]);
});

it('ignora tipos desconocidos y entradas malformadas sin reventar', function (): void {
    $value = [
        'rules' => [
            'TIPO_INEXISTENTE' => ['channels' => ['EMAIL']],
            'EN_ROUTE' => ['channels' => ['CANAL_FALSO']],       // canal inválido -> se descarta
            'DELIVERED' => ['sin_channels' => true],              // sin channels -> se descarta
            'ARRIVING' => ['channels' => ['PUSH_FCM']],           // válida
        ],
    ];

    $policies = CascadeRuleCodec::decode($value);

    expect($policies)->toHaveCount(1)
        ->and($policies)->toHaveKey('ARRIVING')
        ->and($policies['ARRIVING']->channels)->toBe([Channel::PushFcm]);
});

it('hace upsert del override de un tipo preservando los demás', function (): void {
    $current = ['rules' => ['EN_ROUTE' => ['channels' => ['EMAIL'], 'escalate_when' => 'NO_OPEN']]];
    $policy = CascadePolicy::of([Channel::PushFcm, Channel::Sms], EscalateCondition::OnFailure);

    $next = CascadeRuleCodec::upsert($current, MessageType::Delivered, $policy);

    expect($next['rules'])->toHaveKeys(['EN_ROUTE', 'DELIVERED'])
        ->and($next['rules']['DELIVERED'])->toBe([
            'channels' => ['PUSH_FCM', 'SMS'],
            'escalate_when' => 'ON_FAILURE',
        ]);
});

it('quita el override de un tipo dejando intactos los demás', function (): void {
    $current = [
        'rules' => [
            'EN_ROUTE' => ['channels' => ['EMAIL']],
            'DELIVERED' => ['channels' => ['SMS']],
        ],
    ];

    $next = CascadeRuleCodec::remove($current, MessageType::EnRoute);

    expect($next['rules'])->not->toHaveKey('EN_ROUTE')
        ->and($next['rules'])->toHaveKey('DELIVERED');
});
