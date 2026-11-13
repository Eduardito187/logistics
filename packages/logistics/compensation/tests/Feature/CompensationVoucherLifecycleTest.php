<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Compensation\Application\Command\ApproveCompensationCase\ApproveCompensationCase;
use Logistics\Compensation\Application\Command\ExpireVouchers\ExpireVouchers;
use Logistics\Compensation\Application\Command\HoldVoucher\HoldVoucher;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Application\Command\RedeemVoucher\RedeemVoucher;
use Logistics\Compensation\Application\Command\ReleaseVoucherHold\ReleaseVoucherHold;
use Logistics\Compensation\Application\Command\RevokeVoucher\RevokeVoucher;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Shared\Application\Command\CommandBus;

/*
| Ciclo de vida del vale y del caso, atravesando CommandBus → handler → dominio → repositorio → outbox
| contra PostgreSQL real (logistics_test). Cada prueba abre su propio caso, así que no comparte estado.
*/

const CVL_DAMAGED = 'DAMAGED_PRODUCT';
const CVL_SCOPE_LINE = 'LINE';
const CVL_SKU = 'SKU-77231';
const CVL_PRODUCT = 'Refrigerador Mabe 300L';
const CVL_POS = 'pos';
const CVL_VOUCHERS = 'compensation_vouchers';
const CVL_CASES = 'compensation_cases';

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Abre un caso DAMAGED con base declarada y devuelve la fila del vale emitida (o null si quedó propuesto).
 *
 * @return object{id: string, code: string, case_id: string, amount_cents: int}|null
 */
function cvlOpenDamaged(CommandBus $bus, int $baseCents): ?object
{
    $bus->dispatch(new OpenCompensationCase(
        trigger: CVL_DAMAGED,
        scope: CVL_SCOPE_LINE,
        orderReference: 'ORD-'.bin2hex(random_bytes(4)),
        idempotencyKey: 'test:'.bin2hex(random_bytes(6)),
        manualBaseCents: $baseCents,
        manualLines: [[
            'product_name' => CVL_PRODUCT,
            'sku' => CVL_SKU,
            'affected_quantity' => 1,
            'unit_price_cents' => $baseCents,
        ]],
        proposedBy: 'system',
    ));

    /** @var object{id: string, code: string, case_id: string, amount_cents: int}|null $row */
    $row = DB::table(CVL_VOUCHERS)->orderByDesc('created_at')->first();

    return $row;
}

it('auto-emite un vale por daño con monto bajo (caso aprobado por el sistema)', function (): void {
    // Bs 200 de base × 15 % = Bs 30 ≤ tope de auto-aprobación (Bs 50): el sistema aprueba y emite.
    $voucher = cvlOpenDamaged($this->bus, 20_000);

    expect($voucher)->not->toBeNull()
        ->and($voucher->amount_cents)->toBe(3_000)
        ->and($voucher->code)->toMatch('/^DT-[0-9A-Z]{4}-[0-9A-Z]{4}$/');

    expect(DB::table(CVL_CASES)->where('id', $voucher->case_id)->value('status'))->toBe('issued');
});

it('emite el vale de forma manual: caso propuesto y luego aprobado por otro actor (cuatro ojos)', function (): void {
    // Bs 1 000 × 15 % = Bs 150 > Bs 50: no auto-aprueba, queda propuesto para un supervisor.
    $this->bus->dispatch(new OpenCompensationCase(
        trigger: CVL_DAMAGED,
        scope: CVL_SCOPE_LINE,
        orderReference: 'ORD-'.bin2hex(random_bytes(4)),
        idempotencyKey: 'test:'.bin2hex(random_bytes(6)),
        manualBaseCents: 100_000,
        manualLines: [[
            'product_name' => CVL_PRODUCT,
            'sku' => CVL_SKU,
            'affected_quantity' => 1,
            'unit_price_cents' => 100_000,
        ]],
        proposedBy: 'system',
    ));

    $case = DB::table(CVL_CASES)->orderByDesc('created_at')->first();
    expect($case->status)->toBe('proposed');
    expect(DB::table(CVL_VOUCHERS)->where('case_id', $case->id)->count())->toBe(0);

    $this->bus->dispatch(new ApproveCompensationCase(
        caseId: $case->id,
        approver: 'supervisor-1',
    ));

    expect(DB::table(CVL_CASES)->where('id', $case->id)->value('status'))->toBe('issued');
    expect(DB::table(CVL_VOUCHERS)->where('case_id', $case->id)->value('amount_cents'))->toBe(15_000);
});

it('abre un caso LOW_RATING como propuesto, sin emitir vale', function (): void {
    $this->bus->dispatch(new OpenCompensationCase(
        trigger: 'LOW_RATING',
        scope: 'SHIPMENT',
        orderReference: 'ORD-'.bin2hex(random_bytes(4)),
        idempotencyKey: 'test:'.bin2hex(random_bytes(6)),
        manualBaseCents: 100_000,
        proposedBy: 'system',
    ));

    $case = DB::table(CVL_CASES)->orderByDesc('created_at')->first();
    expect($case->status)->toBe('proposed');
    expect(DB::table(CVL_VOUCHERS)->where('case_id', $case->id)->count())->toBe(0);
});

it('canjea el vale una sola vez: el segundo canje con otra referencia falla', function (): void {
    $voucher = cvlOpenDamaged($this->bus, 20_000);
    expect($voucher)->not->toBeNull();

    $this->bus->dispatch(new RedeemVoucher(
        code: $voucher->code,
        channel: CVL_POS,
        saleRef: 'SALE-A',
        amountUsedCents: 3_000,
    ));

    expect(DB::table(CVL_VOUCHERS)->where('id', $voucher->id)->value('status'))->toBe('redeemed');

    // Reintento con la MISMA referencia: idempotente, no lanza.
    $this->bus->dispatch(new RedeemVoucher(
        code: $voucher->code,
        channel: CVL_POS,
        saleRef: 'SALE-A',
        amountUsedCents: 3_000,
    ));

    // Segundo canje con OTRA referencia: el vale ya se usó.
    expect(fn () => $this->bus->dispatch(new RedeemVoucher(
        code: $voucher->code,
        channel: CVL_POS,
        saleRef: 'SALE-B',
        amountUsedCents: 3_000,
    )))->toThrow(CompensationException::class);
});

it('reserva (hold) y luego libera (release) un vale sin canjearlo', function (): void {
    $voucher = cvlOpenDamaged($this->bus, 20_000);
    expect($voucher)->not->toBeNull();

    $this->bus->dispatch(new HoldVoucher(code: $voucher->code, channel: CVL_POS, ttlMinutes: 10));
    expect(DB::table(CVL_VOUCHERS)->where('id', $voucher->id)->value('held_until'))->not->toBeNull();

    $this->bus->dispatch(new ReleaseVoucherHold(code: $voucher->code));
    expect(DB::table(CVL_VOUCHERS)->where('id', $voucher->id)->value('held_until'))->toBeNull();
});

it('anula (revoca) un vale emitido y rechaza anular uno ya canjeado', function (): void {
    $voucher = cvlOpenDamaged($this->bus, 20_000);
    expect($voucher)->not->toBeNull();

    $this->bus->dispatch(new RevokeVoucher(
        voucherId: $voucher->id,
        actor: 'supervisor-1',
        reason: 'Pedido cancelado por el cliente.',
    ));

    expect(DB::table(CVL_VOUCHERS)->where('id', $voucher->id)->value('status'))->toBe('revoked');
});

it('vence los vales emitidos cuya fecha ya pasó', function (): void {
    $voucher = cvlOpenDamaged($this->bus, 20_000);
    expect($voucher)->not->toBeNull();

    // Retro-datar el vencimiento para que entre al lote del cron.
    DB::table(CVL_VOUCHERS)->where('id', $voucher->id)->update(['expires_at' => now()->subDay()]);

    $this->bus->dispatch(new ExpireVouchers(limit: 100));

    expect(DB::table(CVL_VOUCHERS)->where('id', $voucher->id)->value('status'))->toBe('expired');
});
