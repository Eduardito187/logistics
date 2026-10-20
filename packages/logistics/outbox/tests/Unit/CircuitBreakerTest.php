<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Outbox\Infrastructure\Resilience\CircuitBreaker;
use Logistics\Outbox\Infrastructure\Resilience\CircuitOpenException;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Circuit breaker por destino (A-5): abre tras N fallos seguidos, corta rápido (fast-fail) durante el
 * enfriamiento y pasa a half-open cuando este vence. Estado en una cache en memoria (ArrayStore), reloj
 * mutable para controlar el tiempo sin tocar el reloj real.
 */

/**
 * Reloj mutable: permite avanzar el tiempo en el test (para ejercitar el enfriamiento y el half-open).
 */
final class MutableTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $at) {}

    public function now(): DateTimeImmutable
    {
        return $this->at;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->at = $this->at->modify("+{$seconds} seconds");
    }
}

function breakerCache(): CacheRepository
{
    return new CacheRepository(new ArrayStore);
}

it('abre el circuito tras alcanzar el umbral de fallos seguidos y corta rápido', function (): void {
    $clock = new MutableTestClock(new DateTimeImmutable('2026-10-20T12:00:00+00:00'));
    // Umbral 3, enfriamiento 30s, ventana 60s.
    $breaker = new CircuitBreaker(breakerCache(), $clock, 3, 30, 60, 'test');

    $boom = fn (): bool => throw new RuntimeException('destino caído');

    // Primeros 3 intentos: llegan al destino (y fallan), re-lanzando la excepción real.
    for ($i = 0; $i < 3; $i++) {
        expect(fn () => $breaker->call('sap', $boom))->toThrow(RuntimeException::class, 'destino caído');
    }

    // Circuito ABIERTO: el 4º intento corta rápido SIN ejecutar la operación.
    $executed = false;
    expect(fn () => $breaker->call('sap', function () use (&$executed): bool {
        $executed = true;

        return true;
    }))->toThrow(CircuitOpenException::class);

    expect($executed)->toBeFalse()
        ->and($breaker->allows('sap'))->toBeFalse();
});

it('reintenta en half-open y cierra con un éxito; el aislamiento es por destino', function (): void {
    $clock = new MutableTestClock(new DateTimeImmutable('2026-10-20T12:00:00+00:00'));
    $breaker = new CircuitBreaker(breakerCache(), $clock, 2, 30, 60, 'test');

    // Abrimos el circuito de 'fcm' con 2 fallos.
    for ($i = 0; $i < 2; $i++) {
        try {
            $breaker->call('fcm', fn (): bool => throw new RuntimeException('down'));
        } catch (RuntimeException) {
            // esperado
        }
    }

    expect($breaker->allows('fcm'))->toBeFalse()
        // Aislamiento por destino: otro destino sigue permitido.
        ->and($breaker->allows('email'))->toBeTrue();

    // Avanzamos el reloj más allá del enfriamiento: half-open permite un intento de prueba.
    $clock->advanceSeconds(31);
    expect($breaker->allows('fcm'))->toBeTrue();

    // El intento de prueba sale bien → cierra el circuito (se resetea).
    $result = $breaker->call('fcm', fn (): string => 'ok');
    expect($result)->toBe('ok')
        ->and($breaker->allows('fcm'))->toBeTrue();
});
