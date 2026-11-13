<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Application\Command\RedeemVoucher\RedeemVoucher;
use Logistics\Compensation\Application\Command\RevokeVoucher\RevokeVoucher;
use Logistics\Compensation\Application\Query\ValidateVoucher\ValidateVoucher;
use Logistics\Compensation\Application\ReadModel\VoucherValidityView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/*
| Consulta de validez de un vale (POS / checkout), atravesando QueryBus → handler → dominio, contra
| PostgreSQL real (logistics_test). Cubre las ramas de titular, canjeado, anulado y vencido.
*/

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Emite un vale por auto-aprobación (daño, monto bajo) y devuelve su fila.
 */
function vvqIssueVoucher(CommandBus $bus): object
{
    $bus->dispatch(new OpenCompensationCase(
        trigger: 'DAMAGED_PRODUCT',
        scope: 'LINE',
        orderReference: 'ORD-'.bin2hex(random_bytes(4)),
        idempotencyKey: 'vvq:'.bin2hex(random_bytes(6)),
        manualBaseCents: 20_000,
        manualLines: [[
            'product_name' => 'Aspiradora Electrolux',
            'sku' => 'SKU-AS',
            'affected_quantity' => 1,
            'unit_price_cents' => 20_000,
        ]],
        proposedBy: 'system',
    ));

    /** @var object $row */
    $row = DB::table('compensation_vouchers')->orderByDesc('created_at')->first();

    return $row;
}

it('valida un vale canjeable', function (): void {
    $voucher = vvqIssueVoucher($this->bus);

    /** @var VoucherValidityView $view */
    $view = $this->queries->ask(new ValidateVoucher($voucher->code));

    expect($view->valid)->toBeTrue()
        ->and($view->amountCents)->toBe(3_000);
});

it('devuelve not_found para un código con formato inválido', function (): void {
    /** @var VoucherValidityView $view */
    $view = $this->queries->ask(new ValidateVoucher('no-es-un-codigo'));

    expect($view->valid)->toBeFalse()
        ->and($view->reasonCode)->toBe('voucher_not_found');
});

it('rechaza por titular distinto (vale nominativo)', function (): void {
    // El vale auto-emitido no tiene titular (el envío manual no trae email): forzamos un hash para la rama.
    $voucher = vvqIssueVoucher($this->bus);
    DB::table('compensation_vouchers')->where('id', $voucher->id)->update([
        'holder_email_hash' => hash('sha256', 'titular@dismac.test'),
    ]);

    /** @var VoucherValidityView $view */
    $view = $this->queries->ask(new ValidateVoucher($voucher->code, 'otro@dismac.test'));

    expect($view->valid)->toBeFalse()
        ->and($view->reasonCode)->toBe('voucher_holder_mismatch');
});

it('informa que el vale ya fue canjeado', function (): void {
    $voucher = vvqIssueVoucher($this->bus);
    $this->bus->dispatch(new RedeemVoucher(
        code: $voucher->code,
        channel: 'pos',
        saleRef: 'SALE-VVQ',
        amountUsedCents: 3_000,
    ));

    /** @var VoucherValidityView $view */
    $view = $this->queries->ask(new ValidateVoucher($voucher->code));

    expect($view->valid)->toBeFalse()
        ->and($view->reasonCode)->toBe('voucher_redeemed');
});

it('informa que el vale fue anulado', function (): void {
    $voucher = vvqIssueVoucher($this->bus);
    $this->bus->dispatch(new RevokeVoucher(
        voucherId: $voucher->id,
        actor: 'supervisor-1',
        reason: 'Pedido cancelado por el cliente.',
    ));

    /** @var VoucherValidityView $view */
    $view = $this->queries->ask(new ValidateVoucher($voucher->code));

    expect($view->valid)->toBeFalse()
        ->and($view->reasonCode)->toBe('voucher_revoked');
});

it('informa que el vale está vencido', function (): void {
    $voucher = vvqIssueVoucher($this->bus);
    DB::table('compensation_vouchers')->where('id', $voucher->id)->update(['expires_at' => now()->subDay()]);

    /** @var VoucherValidityView $view */
    $view = $this->queries->ask(new ValidateVoucher($voucher->code));

    expect($view->valid)->toBeFalse()
        ->and($view->reasonCode)->toBe('voucher_expired');
});
