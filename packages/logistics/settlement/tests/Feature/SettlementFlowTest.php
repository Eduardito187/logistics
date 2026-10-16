<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Settlement\Application\Command\ConfirmSettlement\ConfirmSettlement;
use Logistics\Settlement\Application\Command\DraftSellerSettlement\DraftSellerSettlement;
use Logistics\Settlement\Application\Command\MarkSettlementPaid\MarkSettlementPaid;
use Logistics\Settlement\Application\Query\GetSellerStatement\GetSellerStatement;
use Logistics\Settlement\Application\Query\GetSettlement\GetSettlement;
use Logistics\Settlement\Application\Query\ListSettlements\ListSettlements;
use Logistics\Settlement\Application\ReadModel\SellerStatementView;
use Logistics\Settlement\Application\ReadModel\SettlementView;
use Logistics\Settlement\Domain\Exception\SettlementException;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Stack completo del contexto Settlement contra logistics_test: command → handler → repositorio + outbox,
 * lectura cross-context de despachos (tabla shipments) y las consultas CQRS. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un seller (tabla sellers) y N despachos suyos (shipments + shipment_lines) en el periodo.
 *
 * @return string el id del seller
 */
function settlementSeedSellerWithDispatches(string $taxSuffix = '0001'): string
{
    $sellerId = Uuid::generate()->value;

    DB::table('sellers')->insert([
        'id' => $sellerId,
        'legal_name' => 'Marketplace Seller '.$taxSuffix,
        'tax_id' => 'NIT-SET-'.$taxSuffix,
        'status' => 'active',
        'contact_name' => 'Owner',
        'contact_email' => 'owner'.$taxSuffix.'@seller.bo',
        'contact_phone' => '70000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Dos despachos entregados del seller: 600.000 y 400.000 centavos (6.000 y 4.000 BOB).
    // (cantidad × precio unitario) = 300×20 y 200×20 → 6.000 y 4.000 BOB; total 10.000 BOB = 1.000.000 centavos.
    foreach ([['DEL-'.$taxSuffix.'-1', 300, 20.00], ['DEL-'.$taxSuffix.'-2', 200, 20.00]] as [$ext, $qty, $unit]) {
        $shipmentId = Uuid::generate()->value;
        DB::table('shipments')->insert([
            'id' => $shipmentId,
            'source' => 'seller-portal',
            'external_id' => $ext,
            'customer_name' => 'Cliente',
            'address_text' => 'Av. Siempre Viva 123',
            'address_confidence' => 90,
            'address_confidence_level' => 'high',
            'status' => 'DELIVERED',
            'owner_party_id' => $sellerId,
            'fulfillment_mode' => 'fbd',
            'created_at' => '2026-09-10 10:00:00',
            'updated_at' => '2026-09-15 10:00:00',
        ]);
        DB::table('shipment_lines')->insert([
            'id' => Uuid::generate()->value,
            'shipment_id' => $shipmentId,
            'sku' => 'SKU-'.$ext,
            'product_name' => 'Producto',
            'quantity' => $qty,
            'unit_price' => $unit,
            'unit_price_currency' => 'BOB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $sellerId;
}

it('arma el borrador agregando los despachos del seller y encola el evento en el outbox', function (): void {
    $sellerId = settlementSeedSellerWithDispatches('A001');

    $this->commands->dispatch(new DraftSellerSettlement(
        sellerId: $sellerId,
        periodFrom: '2026-09-01',
        periodTo: '2026-09-30',
        commissionBasisPoints: 1500,
        fixedFeeCents: 2_000,
    ));

    /** @var SellerSettlementRecord $record */
    $record = SellerSettlementRecord::query()->where('seller_id', $sellerId)->firstOrFail();

    // bruto = 300×20 + 200×20 = 10.000 BOB = 1.000.000 cents; comisión 15% = 150.000; fee 2.000×2 = 4.000.
    expect($record->gross_cents)->toBe(1_000_000)
        ->and($record->commission_cents)->toBe(150_000)
        ->and($record->fixed_fee_cents)->toBe(4_000)
        ->and($record->net_cents)->toBe(846_000)
        ->and($record->lines()->count())->toBe(2);

    expect(DB::table('outbox_messages')->where('event_name', 'settlement.drafted')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('falla al armar una liquidación de un seller sin despachos en el periodo', function (): void {
    $sellerId = Uuid::generate()->value;

    expect(fn () => $this->commands->dispatch(new DraftSellerSettlement(
        sellerId: $sellerId,
        periodFrom: '2026-09-01',
        periodTo: '2026-09-30',
        commissionBasisPoints: 1500,
        fixedFeeCents: 0,
    )))->toThrow(SettlementException::class);
});

it('confirma y paga una liquidación, y la expone por GetSettlement con el nombre del seller', function (): void {
    $sellerId = settlementSeedSellerWithDispatches('A002');

    $this->commands->dispatch(new DraftSellerSettlement(
        sellerId: $sellerId,
        periodFrom: '2026-09-01',
        periodTo: '2026-09-30',
        commissionBasisPoints: 1200,
        fixedFeeCents: 0,
    ));
    $id = (string) SellerSettlementRecord::query()->where('seller_id', $sellerId)->firstOrFail()->getKey();

    $this->commands->dispatch(new ConfirmSettlement(settlementId: $id));
    $this->commands->dispatch(new MarkSettlementPaid(settlementId: $id, paymentReference: 'TRX-7777'));

    /** @var SettlementView|null $view */
    $view = $this->queries->ask(new GetSettlement(settlementId: $id));

    expect($view)->not->toBeNull()
        ->and($view->toArray()['status'])->toBe('paid')
        ->and($view->toArray()['payment_reference'])->toBe('TRX-7777')
        ->and($view->toArray()['seller_name'])->toBe('Marketplace Seller A002');
});

it('lista liquidaciones filtrando por seller y arma el statement agregado', function (): void {
    $sellerId = settlementSeedSellerWithDispatches('A003');

    $this->commands->dispatch(new DraftSellerSettlement(
        sellerId: $sellerId,
        periodFrom: '2026-09-01',
        periodTo: '2026-09-30',
        commissionBasisPoints: 1500,
        fixedFeeCents: 2_000,
    ));

    /** @var list<SettlementView> $list */
    $list = $this->queries->ask(new ListSettlements(sellerId: $sellerId));
    expect($list)->toHaveCount(1);

    /** @var SellerStatementView $statement */
    $statement = $this->queries->ask(new GetSellerStatement(sellerId: $sellerId));
    expect($statement->toArray())->toMatchArray([
        'settlement_count' => 1,
        'gross_cents' => 1_000_000,
        'net_cents' => 846_000,
        'pending_cents' => 846_000,
        'paid_cents' => 0,
    ]);
});
