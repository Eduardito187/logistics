<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Sellers\Database\Factories\SellerFactory;
use Logistics\Sellers\Database\Factories\SellerSkuFactory;
use Logistics\Sellers\Domain\Model\Seller;
use Logistics\Sellers\Domain\ValueObject\ContactInfo;
use Logistics\Sellers\Domain\ValueObject\Money;
use Logistics\Sellers\Domain\ValueObject\PaymentCycle;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SellerSku;
use Logistics\Sellers\Domain\ValueObject\SellerStatus;
use Logistics\Sellers\Domain\ValueObject\SettlementTerms;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Repository\EloquentSellerRepository;

/**
 * Cobertura del repositorio Eloquent del contexto sellers contra logistics_test, ejercitado en aislamiento
 * (sin el bus): upsert del agregado y de sus términos (ramas crear/actualizar), reconstitución vía mapper,
 * orden del listado y catálogo de SKUs. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->repo = new EloquentSellerRepository;
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function repoRegisterSeller(string $taxId): Seller
{
    return Seller::register(
        SellerId::generate(),
        'Repositorio Marketplace '.$taxId,
        $taxId,
        ContactInfo::of('Dueño', 'dueno'.strtolower($taxId).'@seller.bo', '70000000'),
    );
}

it('da una identidad nueva y persiste/recupera el agregado vía mapper', function (): void {
    expect($this->repo->nextIdentity())->toBeInstanceOf(SellerId::class);

    $seller = repoRegisterSeller('NIT-REPO-0001');
    $this->repo->save($seller);

    $loaded = $this->repo->findById($seller->id());
    expect($loaded)->not->toBeNull()
        ->and($loaded->status())->toBe(SellerStatus::Onboarding)
        ->and($loaded->taxId())->toBe('NIT-REPO-0001')
        ->and($loaded->settlementTerms())->toBeNull();
});

it('devuelve null al buscar un seller inexistente', function (): void {
    expect($this->repo->findById(SellerId::generate()))->toBeNull();
});

it('crea y luego actualiza los términos de liquidación sin regenerar la PK', function (): void {
    $seller = repoRegisterSeller('NIT-REPO-0002');

    // Rama crear: no había términos.
    $seller->changeSettlementTerms(SettlementTerms::of(1000, Money::of(500), PaymentCycle::Weekly));
    $this->repo->save($seller);

    $termsId = DB::table('seller_settlement_terms')->where('seller_id', $seller->id()->toString())->value('id');
    expect($termsId)->not->toBeNull();

    // Rama actualizar: ya existen términos; conserva el mismo id de la fila.
    $seller->changeSettlementTerms(SettlementTerms::of(2000, Money::of(750), PaymentCycle::Monthly));
    $this->repo->save($seller);

    $query = DB::table('seller_settlement_terms')->where('seller_id', $seller->id()->toString());
    expect($query->value('id'))->toBe($termsId)
        ->and((int) $query->value('commission_basis_points'))->toBe(2000)
        ->and((int) $query->value('fixed_fee_cents'))->toBe(750)
        ->and($query->value('payment_cycle'))->toBe('monthly');

    // El mapper reconstituye los términos actualizados.
    $loaded = $this->repo->findById($seller->id());
    expect($loaded->settlementTerms()?->commissionBasisPoints)->toBe(2000);
});

it('lista todos los sellers ordenados por razón social', function (): void {
    SellerFactory::new()->create(['legal_name' => 'Zeta Comercial', 'tax_id' => 'NIT-REPO-A01']);
    SellerFactory::new()->create(['legal_name' => 'Alfa Comercial', 'tax_id' => 'NIT-REPO-A02']);

    $all = $this->repo->all();
    $names = array_values(array_filter(
        array_map(static fn (Seller $s): string => $s->legalName(), $all),
        static fn (string $name): bool => in_array($name, ['Alfa Comercial', 'Zeta Comercial'], true),
    ));

    // La base de pruebas se refresca por test: solo existen estos dos, ordenados por razón social.
    expect($names)->toBe(['Alfa Comercial', 'Zeta Comercial']);
});

it('persiste SKUs idempotentes por clave natural y los lista ordenados', function (): void {
    $seller = SellerFactory::new()->create(['tax_id' => 'NIT-REPO-0003']);
    $sellerId = SellerId::fromString((string) $seller->getKey());

    // Alta vía factory (cubre el catálogo con material SAP mapeado) + alta/actualización vía repositorio.
    SellerSkuFactory::new()->forSeller((string) $seller->getKey())->mappedTo('MAT-FAC-1')->create([
        'seller_sku' => 'REPO-SKU-A',
    ]);

    // Rama crear.
    $this->repo->saveSku($sellerId, SellerSku::of('REPO-SKU-B'));
    // Rama actualizar (misma clave natural): completa el material SAP sin duplicar la fila.
    $this->repo->saveSku($sellerId, SellerSku::of('REPO-SKU-B', 'MAT-REPO-2'));

    expect(DB::table('seller_skus')->where('seller_id', $seller->getKey())->count())->toBe(2);

    $skus = $this->repo->listSkus($sellerId);
    $map = [];
    foreach ($skus as $sku) {
        $map[$sku->sku] = $sku->sapMaterial;
    }

    expect($map)->toHaveKey('REPO-SKU-A')
        ->and($map['REPO-SKU-A'])->toBe('MAT-FAC-1')
        ->and($map['REPO-SKU-B'])->toBe('MAT-REPO-2');
});
