<?php

declare(strict_types=1);

use Logistics\Returns\Domain\Exception\ReturnsException;
use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\Disposition;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnLine;
use Logistics\Returns\Domain\ValueObject\ReturnStatus;

/**
 * Casos borde del ciclo de vida de ReturnOrder que ReturnOrderTest no ejercitaba: recibir una devolución que
 * ya pasó de Received (transición inválida) y la idempotencia de close() sobre una ya cerrada. Dominio puro.
 */
function returnOrderEdgeSample(): ReturnOrder
{
    return ReturnOrder::initiate(
        ReturnId::generate(),
        'SHP-EDGE-1',
        '01931000-0000-7000-8000-000000000777',
        '01931001-0000-7000-8000-000000000888',
        ReturnKind::CustomerReturn,
        [ReturnLine::of('BEL-TV-55', 1, 'damaged')],
    );
}

it('no se puede recibir una devolución que ya fue dispuesta', function (): void {
    $returnOrder = returnOrderEdgeSample();
    $returnOrder->receive();
    $returnOrder->dispose(Disposition::Restock);

    expect(fn () => $returnOrder->receive())->toThrow(ReturnsException::class);
});

it('close() es idempotente sobre una devolución ya cerrada', function (): void {
    $returnOrder = returnOrderEdgeSample();
    $returnOrder->receive();
    $returnOrder->dispose(Disposition::Scrap);
    $returnOrder->close();
    $returnOrder->releaseEvents();

    // Segundo cierre: early-return sin excepción ni evento nuevo.
    $returnOrder->close();

    expect($returnOrder->status())->toBe(ReturnStatus::Closed)
        ->and($returnOrder->releaseEvents())->toBe([]);
});
