<?php

declare(strict_types=1);

use Logistics\Settlement\Infrastructure\CrossContext\EloquentSellerDirectory;

/**
 * El cortocircuito de lista vacía de `namesByIds` es PHP puro: retorna antes de tocar la base, así que se
 * ejercita sin PostgreSQL (el resto del adaptador lo cubre SettlementFlowTest contra logistics_test).
 */
it('devuelve un mapa vacío sin consultar la base cuando no hay ids', function (): void {
    $directory = new EloquentSellerDirectory;

    expect($directory->namesByIds([]))->toBe([]);
});
