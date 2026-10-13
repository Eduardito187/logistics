<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Model\Geofence;
use Logistics\Telemetry\Domain\Service\MisuseAnalyzer;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Polygon;
use Logistics\Telemetry\Domain\ValueObject\Speed;

// Servicio de dominio MisuseAnalyzer: las 5 reglas de mal uso sobre una lectura. PHP puro, sin dobles de
// framework: recibe TODO por parámetro (geocercas en memoria, umbrales y reloj).

/**
 * Extrae los tipos de alerta detectados.
 *
 * @param  list<array{type: string, severity: string, detail: array<string, mixed>}> $alerts
 * @return list<string>
 */
function tiposDe(array $alerts): array
{
    return array_values(array_map(static fn (array $a): string => $a['type'], $alerts));
}

function geocercaProhibida(): Geofence
{
    return Geofence::define(
        GeofenceId::generate(),
        'Zona Roja',
        GeofenceType::ForbiddenZone,
        Polygon::fromArray([
            ['lat' => -16.55, 'lng' => -68.20],
            ['lat' => -16.55, 'lng' => -68.10],
            ['lat' => -16.45, 'lng' => -68.10],
            ['lat' => -16.45, 'lng' => -68.20],
        ]),
    );
}

// Punto e instante "normales": dentro de horario laboral, sin geocercas prohibidas.
function analizar(
    MisuseAnalyzer $analyzer,
    ?Speed $speed,
    DateTimeImmutable $at,
    ?bool $ignition,
    bool $hasActiveRoute,
    array $geofences = [],
    int $accumulatedIdleSeconds = 0,
    ?GeoPoint $point = null,
): array {
    return $analyzer->analyze(
        $point ?? GeoPoint::of(-17.78, -63.18),
        $speed,
        $at,
        $ignition,
        $hasActiveRoute,
        $geofences,
        '06:00',               // inicio horario laboral
        '20:00',               // fin horario laboral
        'America/La_Paz',      // zona horaria
        90.0,                  // límite de velocidad (km/h)
        3.0,                   // umbral de movimiento (km/h)
        15,                    // minutos de inactividad para alertar
        $accumulatedIdleSeconds,
    );
}

it('detecta exceso de velocidad', function (): void {
    $analyzer = new MisuseAnalyzer;
    $at = new DateTimeImmutable('2026-10-13T10:00:00-04:00'); // 10:00 en La Paz

    $alerts = analizar($analyzer, Speed::kmh(100.0), $at, true, true);

    expect(tiposDe($alerts))->toContain(AlertType::Speeding->value);
});

it('detecta movimiento fuera del horario laboral', function (): void {
    $analyzer = new MisuseAnalyzer;
    $at = new DateTimeImmutable('2026-10-13T23:00:00-04:00'); // 23:00 en La Paz, fuera de 06:00–20:00

    $alerts = analizar($analyzer, Speed::kmh(40.0), $at, true, true);

    expect(tiposDe($alerts))->toContain(AlertType::AfterHoursMovement->value);
});

it('detecta incursión en una geocerca de zona prohibida', function (): void {
    $analyzer = new MisuseAnalyzer;
    $at = new DateTimeImmutable('2026-10-13T10:00:00-04:00');
    $inside = GeoPoint::of(-16.50, -68.15);

    $alerts = analizar($analyzer, Speed::kmh(40.0), $at, true, true, [geocercaProhibida()], 0, $inside);

    expect(tiposDe($alerts))->toContain(AlertType::GeofenceViolation->value);
});

it('no detecta violación si la geocerca prohibida está desactivada', function (): void {
    $analyzer = new MisuseAnalyzer;
    $at = new DateTimeImmutable('2026-10-13T10:00:00-04:00');
    $inside = GeoPoint::of(-16.50, -68.15);

    $geofence = geocercaProhibida();
    $geofence->deactivate();

    $alerts = analizar($analyzer, Speed::kmh(40.0), $at, true, true, [$geofence], 0, $inside);

    expect(tiposDe($alerts))->not->toContain(AlertType::GeofenceViolation->value);
});

it('detecta inactividad prolongada con motor encendido', function (): void {
    $analyzer = new MisuseAnalyzer;
    $at = new DateTimeImmutable('2026-10-13T10:00:00-04:00');

    // Detenido (velocidad 0), motor encendido, ocioso acumulado por sobre 15 min.
    $alerts = analizar($analyzer, Speed::kmh(0.0), $at, true, true, [], 16 * 60);

    expect(tiposDe($alerts))->toContain(AlertType::ExcessiveIdle->value);
});

it('detecta uso sin ruta asignada activa', function (): void {
    $analyzer = new MisuseAnalyzer;
    $at = new DateTimeImmutable('2026-10-13T10:00:00-04:00');

    // En movimiento, sin ruta activa, velocidad bajo el límite.
    $alerts = analizar($analyzer, Speed::kmh(40.0), $at, true, false);

    expect(tiposDe($alerts))->toContain(AlertType::UnauthorizedUse->value);
});

it('no levanta alertas en una lectura normal dentro de parámetros', function (): void {
    $analyzer = new MisuseAnalyzer;
    $at = new DateTimeImmutable('2026-10-13T10:00:00-04:00');

    $alerts = analizar($analyzer, Speed::kmh(40.0), $at, true, true);

    expect($alerts)->toBe([]);
});
