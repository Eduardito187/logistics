<?php

declare(strict_types=1);

use App\Logging\SlowQueryListener;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

// Listener de consultas lentas (auditoría M-6): loguea sobre el umbral, calla por debajo, no loguea si
// está deshabilitado y nunca registra los valores de los bindings (redacción de PII).

afterEach(function (): void {
    Mockery::close();
});

/**
 * Construye un evento QueryExecuted con la duración dada (ms). La conexión real no se usa en el
 * listener más allá del nombre, así que basta con un doble ligero.
 */
function queryEvent(float $timeMs, array $bindings = []): QueryExecuted
{
    $connection = Mockery::mock(Connection::class);
    $connection->allows('getName')->andReturns('pgsql');

    return new QueryExecuted('select * from shipments where id = ?', $bindings, $timeMs, $connection);
}

/**
 * @return array{0: SlowQueryListener, 1: TestHandler}
 */
function makeListener(bool $enabled, float $thresholdMs): array
{
    $handler = new TestHandler;
    $logger = new Logger('test', [$handler]);

    return [new SlowQueryListener($logger, $enabled, $thresholdMs), $handler];
}

it('logs a warning when the query exceeds the threshold', function (): void {
    [$listener, $handler] = makeListener(enabled: true, thresholdMs: 200.0);

    $listener(queryEvent(350.0));

    expect($handler->hasWarningRecords())->toBeTrue();

    $record = $handler->getRecords()[0];
    expect($record['message'])->toBe('slow query')
        ->and($record['context']['time_ms'])->toBe(350.0)
        ->and($record['context']['threshold_ms'])->toBe(200.0)
        ->and($record['context']['connection'])->toBe('pgsql');
});

it('does not log when the query is below the threshold', function (): void {
    [$listener, $handler] = makeListener(enabled: true, thresholdMs: 200.0);

    $listener(queryEvent(50.0));

    expect($handler->hasWarningRecords())->toBeFalse()
        ->and($handler->getRecords())->toBeEmpty();
});

it('does nothing when disabled even over the threshold', function (): void {
    [$listener, $handler] = makeListener(enabled: false, thresholdMs: 200.0);

    $listener(queryEvent(9999.0));

    expect($handler->getRecords())->toBeEmpty();
});

it('redacts bindings: logs only the count, never the values', function (): void {
    [$listener, $handler] = makeListener(enabled: true, thresholdMs: 100.0);

    $listener(queryEvent(500.0, ['secret@example.com', '70012345']));

    $record = $handler->getRecords()[0];
    expect($record['context'])->toHaveKey('bindings_count')
        ->and($record['context']['bindings_count'])->toBe(2)
        ->and($record['context'])->not->toHaveKey('bindings')
        ->and(json_encode($record['context']))->not->toContain('secret@example.com');
});
