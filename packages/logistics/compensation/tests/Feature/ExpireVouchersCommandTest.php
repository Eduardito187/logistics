<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Shared\Application\Command\CommandBus;

/*
| Comando de consola del cron diario que vence los vales caducados, atravesando el CommandBus real.
*/

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Emite un vale por la vía de auto-aprobación (daño, monto bajo) y devuelve su fila.
 */
function evcIssueVoucher(CommandBus $bus): object
{
    $bus->dispatch(new OpenCompensationCase(
        trigger: 'DAMAGED_PRODUCT',
        scope: 'LINE',
        orderReference: 'ORD-'.bin2hex(random_bytes(4)),
        idempotencyKey: 'cmd:'.bin2hex(random_bytes(6)),
        manualBaseCents: 20_000,
        manualLines: [[
            'product_name' => 'Horno eléctrico',
            'sku' => 'SKU-HE',
            'affected_quantity' => 1,
            'unit_price_cents' => 20_000,
        ]],
        proposedBy: 'system',
    ));

    /** @var object $row */
    $row = DB::table('compensation_vouchers')->orderByDesc('created_at')->first();

    return $row;
}

it('el comando de consola vence los vales caducados', function (): void {
    $voucher = evcIssueVoucher($this->bus);
    DB::table('compensation_vouchers')->where('id', $voucher->id)->update(['expires_at' => now()->subDay()]);

    $this->artisan('logistics:compensation:expire-vouchers', ['--limit' => 50])
        ->assertExitCode(0);

    expect(DB::table('compensation_vouchers')->where('id', $voucher->id)->value('status'))->toBe('expired');
});

it('el comando acepta la corrida por defecto sin límite explícito', function (): void {
    $this->artisan('logistics:compensation:expire-vouchers')
        ->assertExitCode(0);
});
