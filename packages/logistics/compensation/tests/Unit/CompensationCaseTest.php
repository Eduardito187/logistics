<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Event\CaseApproved;
use Logistics\Compensation\Domain\Event\CaseOpened;
use Logistics\Compensation\Domain\Event\CaseRejected;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\Model\CompensationCase;
use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\ApprovalLevel;
use Logistics\Compensation\Domain\ValueObject\CalculationResult;
use Logistics\Compensation\Domain\ValueObject\CalculationTrace;
use Logistics\Compensation\Domain\ValueObject\CaseStatus;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\TriggerType;

// Agregado CASO: custodia cuatro ojos, override solo a la baja y una sola decisión.

function openCase(
    bool $compensable = true,
    int $effectiveCents = 3_000,
    string $proposedBy = 'system',
    array $dimensions = [],
): CompensationCase {
    $affected = AffectedValue::manual(Money::of(20_000));
    $trace = CalculationTrace::empty()->with('base', Money::of(20_000));
    $result = $compensable
        ? CalculationResult::compensable(Money::of($effectiveCents), Money::of($effectiveCents), $trace)
        : CalculationResult::notCompensable(Money::of($effectiveCents), 'E < mínimo', $trace);

    return CompensationCase::open(
        id: CompensationCaseId::generate(),
        trigger: TriggerType::DamagedProduct,
        scope: CompensationScope::Line,
        shipmentId: 'ship-1',
        orderReference: 'ORD-1',
        sourceEventId: null,
        idempotencyKey: 'key-'.bin2hex(random_bytes(4)),
        affected: $affected,
        result: $result,
        approvalLevel: ApprovalLevel::Level1,
        policyVersion: 1,
        proposedBy: $proposedBy,
        dimensions: $dimensions,
    );
}

it('un caso compensable nace propuesto y graba CaseOpened', function (): void {
    $case = openCase(dimensions: ['ownership' => 'OWNED']);

    expect($case->status())->toBe(CaseStatus::Proposed)
        ->and($case->effective()->cents)->toBe(3_000)
        ->and($case->trigger())->toBe(TriggerType::DamagedProduct)
        ->and($case->scope())->toBe(CompensationScope::Line)
        ->and($case->shipmentId())->toBe('ship-1')
        ->and($case->orderReference())->toBe('ORD-1')
        ->and($case->dimensions()['ownership'])->toBe('OWNED');

    $events = $case->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CaseOpened::class);
});

it('un caso no compensable nace NotCompensable con el motivo', function (): void {
    $case = openCase(compensable: false);

    expect($case->status())->toBe(CaseStatus::NotCompensable)
        ->and($case->reason())->toBe('E < mínimo');
});

it('rechaza abrir un caso sin proponente', function (): void {
    expect(fn () => openCase(proposedBy: '   '))->toThrow(CompensationException::class);
});

it('aprueba un caso propuesto y graba CaseApproved', function (): void {
    $case = openCase();
    $case->releaseEvents();

    $case->approve('supervisor-1', new DateTimeImmutable);

    expect($case->status())->toBe(CaseStatus::Approved)
        ->and($case->approvedBy())->toBe('supervisor-1')
        ->and($case->decidedAt())->not->toBeNull();

    $events = $case->releaseEvents();
    expect($events[0])->toBeInstanceOf(CaseApproved::class);
});

it('el sistema puede aprobar aunque sea el proponente (AUTO)', function (): void {
    $case = openCase(proposedBy: CompensationCase::SYSTEM_ACTOR);

    $case->approve(CompensationCase::SYSTEM_ACTOR, new DateTimeImmutable);

    expect($case->status())->toBe(CaseStatus::Approved);
});

it('cuatro ojos: el proponente humano no puede aprobar su propio caso', function (): void {
    $case = openCase(proposedBy: 'call-center-1');

    expect(fn () => $case->approve('call-center-1', new DateTimeImmutable))
        ->toThrow(CompensationException::class);
});

it('rechaza aprobar con aprobador vacío', function (): void {
    $case = openCase();

    expect(fn () => $case->approve('  ', new DateTimeImmutable))->toThrow(CompensationException::class);
});

it('solo se puede aprobar un caso propuesto', function (): void {
    $case = openCase();
    $case->approve('supervisor-1', new DateTimeImmutable);

    expect(fn () => $case->approve('supervisor-2', new DateTimeImmutable))
        ->toThrow(CompensationException::class);
});

it('el override solo baja el monto', function (): void {
    $case = openCase(effectiveCents: 10_000);

    $case->approve('supervisor-1', new DateTimeImmutable, Money::of(6_000));

    expect($case->effective()->cents)->toBe(6_000);
});

it('rechaza un override que sube el monto', function (): void {
    $case = openCase(effectiveCents: 10_000);

    expect(fn () => $case->approve('supervisor-1', new DateTimeImmutable, Money::of(12_000)))
        ->toThrow(CompensationException::class);
});

it('rechaza un caso propuesto y graba CaseRejected', function (): void {
    $case = openCase(proposedBy: 'system');
    $case->releaseEvents();

    $case->reject('supervisor-1', 'Pedido cancelado por el cliente.', new DateTimeImmutable);

    expect($case->status())->toBe(CaseStatus::Rejected)
        ->and($case->reason())->toBe('Pedido cancelado por el cliente.');

    $events = $case->releaseEvents();
    expect($events[0])->toBeInstanceOf(CaseRejected::class);
});

it('rechaza rechazar con motivo vacío', function (): void {
    $case = openCase();

    expect(fn () => $case->reject('supervisor-1', '   ', new DateTimeImmutable))
        ->toThrow(CompensationException::class);
});

it('cuatro ojos también al rechazar', function (): void {
    $case = openCase(proposedBy: 'call-center-1');

    expect(fn () => $case->reject('call-center-1', 'motivo', new DateTimeImmutable))
        ->toThrow(CompensationException::class);
});

it('solo se puede rechazar un caso propuesto', function (): void {
    $case = openCase();
    $case->approve('supervisor-1', new DateTimeImmutable);

    expect(fn () => $case->reject('supervisor-2', 'motivo', new DateTimeImmutable))
        ->toThrow(CompensationException::class);
});

it('marca emitido solo desde aprobado', function (): void {
    $case = openCase();

    expect(fn () => $case->markIssued())->toThrow(CompensationException::class);

    $case->approve('supervisor-1', new DateTimeImmutable);
    $case->markIssued();
    expect($case->status())->toBe(CaseStatus::Issued);
});

it('reconstituye un caso desde persistencia sin grabar eventos', function (): void {
    $case = CompensationCase::reconstitute(
        id: CompensationCaseId::generate(),
        trigger: TriggerType::LowRating,
        scope: CompensationScope::Shipment,
        shipmentId: 'ship-9',
        orderReference: null,
        sourceEventId: 'evt-1',
        idempotencyKey: 'k',
        affected: AffectedValue::manual(Money::of(10_000)),
        nominal: Money::of(500),
        effective: Money::of(500),
        policyVersion: 2,
        approvalLevel: ApprovalLevel::Level1,
        status: CaseStatus::Proposed,
        proposedBy: 'system',
        approvedBy: null,
        decidedAt: null,
        reason: null,
        dimensions: [],
    );

    expect($case->policyVersion())->toBe(2)
        ->and($case->nominal()->cents)->toBe(500)
        ->and($case->sourceEventId())->toBe('evt-1')
        ->and($case->idempotencyKey())->toBe('k')
        ->and($case->approvalLevel())->toBe(ApprovalLevel::Level1)
        ->and($case->releaseEvents())->toBe([]);
});
