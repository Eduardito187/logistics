<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Sellers\Application\Command\ActivateSeller\ActivateSeller;
use Logistics\Sellers\Application\Command\RegisterSeller\RegisterSeller;
use Logistics\Sellers\Application\Command\SetSettlementTerms\SetSettlementTerms;
use Logistics\Sellers\Application\Command\SuspendSeller\SuspendSeller;
use Logistics\Sellers\Application\Command\UpsertSellerSku\UpsertSellerSku;
use Logistics\Sellers\Application\Query\GetSeller\GetSeller;
use Logistics\Sellers\Application\Query\ListSellers\ListSellers;
use Logistics\Sellers\Application\Query\ListSellerSkus\ListSellerSkus;
use Logistics\Sellers\Application\ReadModel\SellerSkuView;
use Logistics\Sellers\Application\ReadModel\SellerView;
use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Stack completo del contexto Sellers contra logistics_test: command → handler → repositorio + outbox,
 * y las consultas CQRS. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function sellersRegisterViaBus(CommandBus $bus, string $taxId = 'NIT-7000001'): string
{
    $bus->dispatch(new RegisterSeller(
        legalName: 'Andina Home & Living Ltda.',
        taxId: $taxId,
        contactName: 'José Vargas',
        email: 'marketplace@andinahome.bo',
        phone: '70011002',
    ));

    /** @var SellerRecord $record */
    $record = SellerRecord::query()->where('tax_id', $taxId)->firstOrFail();

    return (string) $record->getKey();
}

it('registra un seller y encola el evento en el outbox', function (): void {
    $id = sellersRegisterViaBus($this->commands);

    $this->assertDatabaseHas('sellers', [
        'id' => $id,
        'tax_id' => 'NIT-7000001',
        'status' => 'onboarding',
    ]);

    expect(DB::table('outbox_messages')->where('event_name', 'sellers.seller_registered')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('pacta términos de liquidación y los persiste', function (): void {
    $id = sellersRegisterViaBus($this->commands);

    $this->commands->dispatch(new SetSettlementTerms(
        sellerId: $id,
        commissionBasisPoints: 1800,
        fixedFeeCents: 2500,
        currency: 'BOB',
        paymentCycle: 'monthly',
    ));

    $this->assertDatabaseHas('seller_settlement_terms', [
        'seller_id' => $id,
        'commission_basis_points' => 1800,
        'fixed_fee_cents' => 2500,
        'currency' => 'BOB',
        'payment_cycle' => 'monthly',
    ]);

    /** @var SellerView|null $view */
    $view = $this->queries->ask(new GetSeller(sellerId: $id));
    expect($view)->not->toBeNull()
        ->and($view->toArray()['settlement_terms']['commission_basis_points'])->toBe(1800);
});

it('da de alta SKUs idempotentes y los lista', function (): void {
    $id = sellersRegisterViaBus($this->commands);

    $this->commands->dispatch(new UpsertSellerSku(sellerId: $id, sku: 'AHL-SOFA-3P', sapMaterial: null));
    // Segundo upsert del mismo SKU: actualiza, no duplica.
    $this->commands->dispatch(new UpsertSellerSku(sellerId: $id, sku: 'AHL-SOFA-3P', sapMaterial: 'MAT-123'));

    expect(SellerRecord::query()->findOrFail($id)->skus()->count())->toBe(1);

    /** @var list<SellerSkuView> $skus */
    $skus = $this->queries->ask(new ListSellerSkus(sellerId: $id));
    expect($skus)->toHaveCount(1)
        ->and($skus[0]->toArray()['sap_material'])->toBe('MAT-123');
});

it('lista sellers con filtro por estado', function (): void {
    $activeId = sellersRegisterViaBus($this->commands, 'NIT-7000010');
    $this->commands->dispatch(new ActivateSeller(sellerId: $activeId));

    $suspendedId = sellersRegisterViaBus($this->commands, 'NIT-7000011');
    $this->commands->dispatch(new SuspendSeller(sellerId: $suspendedId));

    /** @var list<SellerView> $suspended */
    $suspended = $this->queries->ask(new ListSellers(status: 'suspended'));
    $taxIds = array_map(static fn (SellerView $v): string => $v->taxId, $suspended);

    expect($taxIds)->toContain('NIT-7000011')
        ->and($taxIds)->not->toContain('NIT-7000010');
});

it('falla al operar sobre un seller inexistente', function (): void {
    expect(fn () => $this->commands->dispatch(new SuspendSeller(sellerId: '01931000-0000-7000-8000-0000000fffff')))
        ->toThrow(SellersException::class);
});
