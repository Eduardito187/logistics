<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\FailureReason;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Execution\Domain\ValueObject\FailureReasonId;

/**
 * Dominio del catálogo configurable de motivos de entrega fallida: VO del code (inmutable, slug) y el
 * agregado (alta, renombrado, activar/desactivar, exigencias, reorden). Dominio puro, sin Laravel.
 */
it('deriva el code de una etiqueta como slug en MAYUSCULAS', function (): void {
    expect(FailureReasonCode::fromLabel('Customer absent')->value)->toBe('CUSTOMER_ABSENT')
        ->and(FailureReasonCode::fromLabel('  Rejected by customer! ')->value)->toBe('REJECTED_BY_CUSTOMER');
});

it('normaliza un code dado a MAYUSCULAS', function (): void {
    expect(FailureReasonCode::fromString('customer_absent')->value)->toBe('CUSTOMER_ABSENT');
});

it('rechaza un code vacio', function (): void {
    expect(fn () => FailureReasonCode::fromString('   '))->toThrow(ExecutionException::class);
});

it('crea un motivo con sus valores por defecto', function (): void {
    $reason = FailureReason::create(
        FailureReasonId::generate(),
        FailureReasonCode::fromString('CUSTOMER_ABSENT'),
        'Customer absent',
    );

    expect($reason->code()->value)->toBe('CUSTOMER_ABSENT')
        ->and($reason->label())->toBe('Customer absent')
        ->and($reason->isActive())->toBeTrue()
        ->and($reason->requiresPhoto())->toBeFalse()
        ->and($reason->requiresNote())->toBeFalse()
        ->and($reason->sortOrder())->toBe(0);
});

it('rechaza una etiqueta vacia', function (): void {
    expect(fn () => FailureReason::create(FailureReasonId::generate(), FailureReasonCode::fromString('OTHER'), '  '))
        ->toThrow(ExecutionException::class);
});

it('renombra sin cambiar el code (el code es inmutable)', function (): void {
    $reason = FailureReason::create(FailureReasonId::generate(), FailureReasonCode::fromString('OTHER'), 'Other');
    $reason->relabel('Other reason');

    expect($reason->label())->toBe('Other reason')
        ->and($reason->code()->value)->toBe('OTHER');
});

it('desactiva y reactiva un motivo', function (): void {
    $reason = FailureReason::create(FailureReasonId::generate(), FailureReasonCode::fromString('DAMAGED'), 'Damaged');

    $reason->deactivate();
    expect($reason->isActive())->toBeFalse();

    $reason->activate();
    expect($reason->isActive())->toBeTrue();
});

it('fija las exigencias de foto y nota', function (): void {
    $reason = FailureReason::create(FailureReasonId::generate(), FailureReasonCode::fromString('DAMAGED'), 'Damaged');
    $reason->setRequirements(requiresPhoto: true, requiresNote: true, noteLabel: 'Describe the damage');

    expect($reason->requiresPhoto())->toBeTrue()
        ->and($reason->requiresNote())->toBeTrue()
        ->and($reason->noteLabel())->toBe('Describe the damage');
});

it('reordena un motivo', function (): void {
    $reason = FailureReason::create(FailureReasonId::generate(), FailureReasonCode::fromString('OTHER'), 'Other');
    $reason->reorder(9);

    expect($reason->sortOrder())->toBe(9);
});
