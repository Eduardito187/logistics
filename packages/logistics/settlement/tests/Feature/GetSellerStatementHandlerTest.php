<?php

declare(strict_types=1);

use Logistics\Settlement\Application\Query\GetSellerStatement\GetSellerStatement;
use Logistics\Settlement\Application\ReadModel\SellerStatementView;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory\SellerSettlementFactory;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura del GetSellerStatementHandler contra logistics_test: separa el neto pagado (liquidaciones en
 * estado Paid) del pendiente (borrador/confirmado) y resuelve el caso sin liquidaciones. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('separa el neto pagado del pendiente al agregar las liquidaciones del seller', function (): void {
    $sellerId = Uuid::generate()->value;

    $paid = SellerSettlementFactory::new()->forSeller($sellerId)->paid('PAY-STMT-1')->create([
        'net_cents' => 500_000,
        'gross_cents' => 600_000,
    ]);
    $draft = SellerSettlementFactory::new()->forSeller($sellerId)->create([
        'net_cents' => 300_000,
        'gross_cents' => 350_000,
    ]);

    /** @var SellerStatementView $statement */
    $statement = $this->queries->ask(new GetSellerStatement(sellerId: $sellerId));

    expect($statement->toArray())->toMatchArray([
        'settlement_count' => 2,
        'gross_cents' => $paid->gross_cents + $draft->gross_cents,
        'net_cents' => 800_000,
        'paid_cents' => 500_000,
        'pending_cents' => 300_000,
    ]);
});

it('devuelve un statement vacío en BOB para un seller sin liquidaciones', function (): void {
    /** @var SellerStatementView $statement */
    $statement = $this->queries->ask(new GetSellerStatement(sellerId: Uuid::generate()->value));

    expect($statement->toArray())->toMatchArray([
        'settlement_count' => 0,
        'gross_cents' => 0,
        'net_cents' => 0,
        'paid_cents' => 0,
        'pending_cents' => 0,
        'currency' => 'BOB',
        'settlements' => [],
    ]);
});
