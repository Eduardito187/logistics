<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\App\WatchSummaryController;

// «N de M» del resumen para reloj: regla acordada con la app (idéntica a su RouteProgress).
// N = ATENDIDAS + 1, capado a M; ATENDIDAS = entregadas + FALLIDAS (una fallida avanza; reprogramadas no).

it('cuenta la parada actual como entregadas + 1', function (): void {
    // 2 entregadas, 0 fallidas, 6 totales, con pendientes → parada 3.
    expect(WatchSummaryController::stopNumber(2, 0, 6, true))->toBe(3);
});

it('una parada FALLIDA avanza el contador (igual que la pantalla)', function (): void {
    // 2 entregadas + 1 fallida = 3 atendidas → parada 4.
    expect(WatchSummaryController::stopNumber(2, 1, 6, true))->toBe(4);
});

it('arranca en 1 sin nada atendido', function (): void {
    expect(WatchSummaryController::stopNumber(0, 0, 6, true))->toBe(1);
});

it('capa N a M (nunca mayor que el total)', function (): void {
    expect(WatchSummaryController::stopNumber(5, 3, 6, true))->toBe(6);
});

it('ruta sin pendientes (terminada): N = M', function (): void {
    expect(WatchSummaryController::stopNumber(6, 0, 6, false))->toBe(6);
});

it('sin ruta (M<=0): null', function (): void {
    expect(WatchSummaryController::stopNumber(0, 0, 0, false))->toBeNull();
});
