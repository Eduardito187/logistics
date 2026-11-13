<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Compensation\Application\Command\IssueVoucher\IssueVoucher;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Application\Command\RejectCompensationCase\RejectCompensationCase;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Shared\Application\Command\CommandBus;

/*
| Handlers de emisión y rechazo de un caso, atravesando CommandBus → handler → dominio → repositorio →
| outbox contra PostgreSQL real (logistics_test).
*/

const CCH_DAMAGED = 'DAMAGED_PRODUCT';
const CCH_CASES = 'compensation_cases';
const CCH_VOUCHERS = 'compensation_vouchers';

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Abre un caso DAMAGED con monto bajo (auto-emitido) y devuelve la fila del caso.
 */
function cchIssuedCase(CommandBus $bus): object
{
    $bus->dispatch(new OpenCompensationCase(
        trigger: CCH_DAMAGED,
        scope: 'LINE',
        orderReference: 'ORD-'.bin2hex(random_bytes(4)),
        idempotencyKey: 'hdl:'.bin2hex(random_bytes(6)),
        manualBaseCents: 20_000,
        manualLines: [[
            'product_name' => 'Microondas Samsung',
            'sku' => 'SKU-MW',
            'affected_quantity' => 1,
            'unit_price_cents' => 20_000,
        ]],
        proposedBy: 'system',
    ));

    /** @var object $row */
    $row = DB::table(CCH_CASES)->orderByDesc('created_at')->first();

    return $row;
}

/**
 * Abre un caso LOW_RATING manual (queda propuesto, sin vale).
 */
function cchProposedCase(CommandBus $bus, string $proposedBy = 'system'): object
{
    $bus->dispatch(new OpenCompensationCase(
        trigger: 'LOW_RATING',
        scope: 'SHIPMENT',
        orderReference: 'ORD-'.bin2hex(random_bytes(4)),
        idempotencyKey: 'hdl:'.bin2hex(random_bytes(6)),
        manualBaseCents: 100_000,
        proposedBy: $proposedBy,
    ));

    /** @var object $row */
    $row = DB::table(CCH_CASES)->orderByDesc('created_at')->first();

    return $row;
}

it('IssueVoucher es idempotente sobre un caso ya emitido', function (): void {
    $case = cchIssuedCase($this->bus);
    expect($case->status)->toBe('issued');
    expect(DB::table(CCH_VOUCHERS)->where('case_id', $case->id)->count())->toBe(1);

    $this->bus->dispatch(new IssueVoucher(caseId: $case->id));

    expect(DB::table(CCH_VOUCHERS)->where('case_id', $case->id)->count())->toBe(1);
});

it('IssueVoucher rechaza un caso que no existe', function (): void {
    expect(fn () => $this->bus->dispatch(new IssueVoucher(caseId: '0192f1a0-1111-7abc-8def-000000000000')))
        ->toThrow(CompensationException::class);
});

it('IssueVoucher rechaza un caso que no está aprobado', function (): void {
    $case = cchProposedCase($this->bus);
    expect($case->status)->toBe('proposed');

    expect(fn () => $this->bus->dispatch(new IssueVoucher(caseId: $case->id)))
        ->toThrow(CompensationException::class);
});

it('RejectCompensationCase rechaza un caso propuesto y lo deja en rejected', function (): void {
    $case = cchProposedCase($this->bus);

    $this->bus->dispatch(new RejectCompensationCase(
        caseId: $case->id,
        approver: 'supervisor-1',
        reason: 'No corresponde compensar: el cliente estaba conforme.',
    ));

    expect(DB::table(CCH_CASES)->where('id', $case->id)->value('status'))->toBe('rejected');
});

it('RejectCompensationCase rechaza un caso que no existe', function (): void {
    expect(fn () => $this->bus->dispatch(new RejectCompensationCase(
        caseId: '0192f1a0-2222-7abc-8def-000000000000',
        approver: 'supervisor-1',
        reason: 'motivo',
    )))->toThrow(CompensationException::class);
});

it('RejectCompensationCase respeta cuatro ojos: el proponente humano no puede rechazar', function (): void {
    $case = cchProposedCase($this->bus, proposedBy: 'call-center-1');

    expect(fn () => $this->bus->dispatch(new RejectCompensationCase(
        caseId: $case->id,
        approver: 'call-center-1',
        reason: 'motivo',
    )))->toThrow(CompensationException::class);
});
