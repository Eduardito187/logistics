<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Presentation\Http\Controller\GetCompensationCaseController;
use Logistics\Compensation\Presentation\Http\Controller\HoldVoucherController;
use Logistics\Compensation\Presentation\Http\Controller\OpenCompensationCaseController;
use Logistics\Compensation\Presentation\Http\Controller\RedeemVoucherController;
use Logistics\Compensation\Presentation\Http\Controller\ReleaseVoucherHoldController;
use Logistics\Compensation\Presentation\Http\Controller\ValidateVoucherController;
use Logistics\Shared\Application\Command\CommandBus;

/*
| Endpoints de la puerta `integrations` del contexto Compensación, atravesando controller → FormRequest →
| bus CQRS → dominio → outbox. Las rutas se registran AQUÍ (no se depende del fichero real de rutas) para
| ejercitar el stack completo con los códigos de estado del contrato (200/202/404/409/410/422). Sin
| middleware de puerta: se prueba solo el contrato del payload.
*/

const CHE_VALIDATE = '/api/v1/integrations/vouchers/validate';
const CHE_CASES = '/api/v1/integrations/compensation/cases';
const CHE_DAMAGED = 'DAMAGED_PRODUCT';
const CHE_LINE = 'LINE';
const CHE_POS = 'pos';
const CHE_VOUCHERS = 'compensation_vouchers';
const CHE_BAD_CODE = 'DT-0000-0000';
const CHE_MISSING_CASE = '0192f1a0-1111-7abc-8def-000000000000';

beforeEach(function (): void {
    $this->withoutMiddleware();

    Route::post(CHE_VALIDATE, ValidateVoucherController::class);
    Route::post('/api/v1/integrations/vouchers/{code}/hold', HoldVoucherController::class);
    Route::post('/api/v1/integrations/vouchers/{code}/redeem', RedeemVoucherController::class);
    Route::post('/api/v1/integrations/vouchers/{code}/release', ReleaseVoucherHoldController::class);
    Route::post(CHE_CASES, OpenCompensationCaseController::class);
    Route::get('/api/v1/integrations/compensation/cases/{caseId}', GetCompensationCaseController::class);

    $this->bus = app(CommandBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Emite un vale por la vía de auto-aprobación (daño, monto bajo) y devuelve su fila.
 *
 * @return object{id: string, code: string, case_id: string, amount_cents: int}
 */
function cheIssueVoucher(CommandBus $bus): object
{
    $bus->dispatch(new OpenCompensationCase(
        trigger: CHE_DAMAGED,
        scope: CHE_LINE,
        orderReference: 'ORD-'.bin2hex(random_bytes(4)),
        idempotencyKey: 'http:'.bin2hex(random_bytes(6)),
        manualBaseCents: 20_000,
        manualLines: [[
            'product_name' => 'Televisor Samsung 55"',
            'sku' => 'SKU-55TV',
            'affected_quantity' => 1,
            'unit_price_cents' => 20_000,
        ]],
        proposedBy: 'system',
    ));

    /** @var object{id: string, code: string, case_id: string, amount_cents: int} $row */
    $row = DB::table(CHE_VOUCHERS)->orderByDesc('created_at')->first();

    return $row;
}

it('valida un vale canjeable (200)', function (): void {
    $voucher = cheIssueVoucher($this->bus);

    $this->postJson(CHE_VALIDATE, ['code' => $voucher->code])
        ->assertStatus(200)
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.amount_cents', 3_000);
});

it('valida un código inexistente sin revelar información (200, valid false)', function (): void {
    $this->postJson(CHE_VALIDATE, ['code' => CHE_BAD_CODE])
        ->assertStatus(200)
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.reason', 'voucher_not_found');
});

it('reserva un vale (202) y rechaza una segunda reserva (409)', function (): void {
    $voucher = cheIssueVoucher($this->bus);

    $this->postJson("/api/v1/integrations/vouchers/{$voucher->code}/hold", ['channel' => CHE_POS])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);

    $this->postJson("/api/v1/integrations/vouchers/{$voucher->code}/hold", ['channel' => CHE_POS])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'voucher_held');
});

it('canjea un vale (202), es idempotente por sale_ref (202) y rechaza el segundo canje (409)', function (): void {
    $voucher = cheIssueVoucher($this->bus);
    $base = "/api/v1/integrations/vouchers/{$voucher->code}/redeem";

    $this->postJson($base, ['sale_ref' => 'SALE-1', 'amount_used_cents' => 3_000])->assertStatus(202);
    // Mismo sale_ref: idempotente.
    $this->postJson($base, ['sale_ref' => 'SALE-1', 'amount_used_cents' => 3_000])->assertStatus(202);
    // Otro sale_ref: ya canjeado.
    $this->postJson($base, ['sale_ref' => 'SALE-2', 'amount_used_cents' => 3_000])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'voucher_redeemed');
});

it('libera la reserva de un vale (202)', function (): void {
    $voucher = cheIssueVoucher($this->bus);

    $this->postJson("/api/v1/integrations/vouchers/{$voucher->code}/hold", ['channel' => CHE_POS])->assertStatus(202);
    $this->postJson("/api/v1/integrations/vouchers/{$voucher->code}/release")
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
});

it('devuelve 410 al canjear un vale vencido', function (): void {
    $voucher = cheIssueVoucher($this->bus);
    DB::table(CHE_VOUCHERS)->where('id', $voucher->id)->update(['expires_at' => now()->subDay()]);

    $this->postJson("/api/v1/integrations/vouchers/{$voucher->code}/redeem", ['sale_ref' => 'SALE-X', 'amount_used_cents' => 1_000])
        ->assertStatus(410)
        ->assertJsonPath('reason', 'voucher_expired');
});

it('devuelve 404 al canjear un código inexistente', function (): void {
    $this->postJson('/api/v1/integrations/vouchers/'.CHE_BAD_CODE.'/redeem', ['sale_ref' => 'SALE-Z', 'amount_used_cents' => 1_000])
        ->assertStatus(404)
        ->assertJsonPath('reason', 'voucher_not_found');
});

it('devuelve 422 de validación al canjear sin referencia de venta', function (): void {
    $voucher = cheIssueVoucher($this->bus);

    $this->postJson("/api/v1/integrations/vouchers/{$voucher->code}/redeem", ['amount_used_cents' => 1_000])
        ->assertStatus(422);
});

it('abre un caso de compensación manual (202)', function (): void {
    $this->postJson(CHE_CASES, [
        'trigger' => CHE_DAMAGED,
        'scope' => CHE_LINE,
        'manual_base_cents' => 100_000,
        'manual_lines' => [[
            'product_name' => 'Lavadora LG 18kg',
            'sku' => 'SKU-LG18',
            'affected_quantity' => 1,
            'unit_price_cents' => 100_000,
        ]],
        'proposed_by' => 'call-center-1',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
});

it('rechaza abrir un caso con disparador inválido (422 de validación)', function (): void {
    $this->postJson(CHE_CASES, ['trigger' => 'NOT_A_TRIGGER', 'scope' => CHE_LINE])
        ->assertStatus(422);
});

it('consulta un caso por su id (200) y responde 404 si no existe', function (): void {
    $voucher = cheIssueVoucher($this->bus);

    $this->getJson(CHE_CASES."/{$voucher->case_id}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $voucher->case_id)
        ->assertJsonPath('data.status', 'issued');

    $this->getJson(CHE_CASES.'/'.CHE_MISSING_CASE)
        ->assertStatus(404);
});
