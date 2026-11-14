<?php

declare(strict_types=1);

use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnLine;
use Logistics\Returns\Domain\ValueObject\ReturnStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura del repositorio Eloquent de devoluciones contra logistics_test: upsert con reemplazo de líneas,
 * reconstitución fiel (findById), filtros de listBy/countBy por dueño y estado, y la paginación por
 * offset/limit. Resuelve el contrato por el contenedor (binding del service provider). Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->repo = app(ReturnOrderRepository::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Construye e inicia una devolución de seller (fbd) con una línea.
 */
function repoInitiatedReturn(string $sellerId, string $reference): ReturnOrder
{
    return ReturnOrder::initiate(
        ReturnId::generate(),
        $reference,
        $sellerId,
        Uuid::generate()->value,
        ReturnKind::CustomerReturn,
        [ReturnLine::of('BEL-TV-55', 2, 'damaged')],
    );
}

it('guarda una devolución y la recupera reconstituida con sus líneas', function (): void {
    $sellerId = Uuid::generate()->value;
    $returnOrder = repoInitiatedReturn($sellerId, 'SHP-REPO-1');

    $this->repo->save($returnOrder);

    $found = $this->repo->findById($returnOrder->id());

    expect($found)->not->toBeNull()
        ->and($found->id()->toString())->toBe($returnOrder->id()->toString())
        ->and($found->ownerPartyId())->toBe($sellerId)
        ->and($found->status())->toBe(ReturnStatus::Initiated)
        ->and($found->lines())->toHaveCount(1)
        ->and($found->lines()[0]->sku)->toBe('BEL-TV-55');
});

it('el save es un upsert que reemplaza las líneas en bloque', function (): void {
    $sellerId = Uuid::generate()->value;
    $returnOrder = repoInitiatedReturn($sellerId, 'SHP-REPO-2');
    $this->repo->save($returnOrder);

    // Re-guardar el mismo agregado (idempotente): una sola fila y sus líneas reinsertadas, sin duplicar.
    $this->repo->save($returnOrder);

    $found = $this->repo->findById($returnOrder->id());
    expect($found)->not->toBeNull()
        ->and($found->lines())->toHaveCount(1);
    expect($this->repo->countBy($sellerId))->toBe(1);
});

it('devuelve null al buscar una devolución inexistente', function (): void {
    expect($this->repo->findById(ReturnId::generate()))->toBeNull();
});

it('filtra por dueño y estado en listBy y countBy', function (): void {
    $sellerA = Uuid::generate()->value;
    $sellerB = Uuid::generate()->value;

    $this->repo->save(repoInitiatedReturn($sellerA, 'SHP-REPO-A1'));
    $this->repo->save(repoInitiatedReturn($sellerA, 'SHP-REPO-A2'));
    $this->repo->save(repoInitiatedReturn($sellerB, 'SHP-REPO-B1'));

    expect($this->repo->countBy($sellerA))->toBe(2)
        ->and($this->repo->countBy($sellerA, 'initiated'))->toBe(2)
        ->and($this->repo->countBy($sellerA, 'received'))->toBe(0)
        ->and($this->repo->listBy($sellerA))->toHaveCount(2)
        ->and($this->repo->listBy($sellerB))->toHaveCount(1);
});

it('pagina por offset y limit respetando el orden determinista', function (): void {
    $seller = Uuid::generate()->value;
    $this->repo->save(repoInitiatedReturn($seller, 'SHP-REPO-P1'));
    $this->repo->save(repoInitiatedReturn($seller, 'SHP-REPO-P2'));
    $this->repo->save(repoInitiatedReturn($seller, 'SHP-REPO-P3'));

    $firstPage = $this->repo->listBy($seller, null, 2, 0);
    $secondPage = $this->repo->listBy($seller, null, 2, 2);

    expect($firstPage)->toHaveCount(2)
        ->and($secondPage)->toHaveCount(1)
        ->and($this->repo->countBy($seller))->toBe(3);
});
