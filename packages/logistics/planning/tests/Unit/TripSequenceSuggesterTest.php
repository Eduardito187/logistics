<?php

declare(strict_types=1);

use Logistics\Planning\Application\Service\CustomerTimeWindow;
use Logistics\Planning\Application\Service\TripBuilderSettings;
use Logistics\Planning\Domain\Service\StraightLineMatrixBuilder;
use Logistics\Planning\Domain\Service\TripSequenceSuggester;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\TimeWindow;

/**
 * Respaldo local del asistente de viajes: orden sugerido (vecino más cercano + inserción de las paradas con
 * hora elegida por el cliente), su línea de tiempo y las piezas puras que lo rodean. Sin Laravel ni I/O.
 *
 * Matriz 1D: índice 0 = base (x = 0) y paradas 1..3 en x = {10, 20, 30}; el tiempo y la distancia entre dos
 * puntos son la diferencia absoluta de x. Por geometría pura el mejor orden es 1-2-3.
 */
function planningTripLineMatrix(): DistanceMatrix
{
    $x = [0.0, 10.0, 20.0, 30.0];
    $grid = [];
    foreach ($x as $from) {
        $row = [];
        foreach ($x as $to) {
            $row[] = abs($from - $to);
        }
        $grid[] = $row;
    }

    return DistanceMatrix::fromArrays($grid, $grid);
}

it('sin ventanas ordena por vecino más cercano desde la base', function (): void {
    $order = (new TripSequenceSuggester)->suggest(planningTripLineMatrix(), [3, 1, 2]);

    expect($order)->toBe([1, 2, 3]);
});

it('ubica la parada con ventana para llegar dentro de ella aunque alargue el camino', function (): void {
    $suggester = new TripSequenceSuggester;
    $matrix = planningTripLineMatrix();
    // La parada 3 cierra a los 30 s y cada entrega tarda 5 s: en el orden natural 1-2-3 se llega a los 40 s.
    $windows = [3 => TimeWindow::of(0.0, 30.0)];

    $order = $suggester->suggest($matrix, [1, 2, 3], $windows, 5.0);
    $timeline = $suggester->timeline($matrix, $order, $windows, 5.0);

    expect($order)->toBe([3, 2, 1])
        ->and($timeline['late'])->toBe([false, false, false])
        ->and($timeline['lateness_seconds'])->toBe(0.0)
        ->and($timeline['arrivals'])->toBe([30.0, 45.0, 60.0]);
});

it('calcula la línea de tiempo con espera de ventana y tiempo de servicio', function (): void {
    $suggester = new TripSequenceSuggester;
    // La parada 1 abre a los 50 s: se llega a los 10 s y se espera hasta la apertura.
    $timeline = $suggester->timeline(planningTripLineMatrix(), [1, 2, 3], [1 => TimeWindow::of(50.0, 100.0)], 5.0);

    expect($timeline['arrivals'])->toBe([50.0, 65.0, 80.0])
        ->and($timeline['late'])->toBe([false, false, false])
        ->and($timeline['distance_meters'])->toBe(30.0)
        ->and($timeline['duration_seconds'])->toBe(85.0);
});

it('cuando ninguna posición cumple la ventana devuelve igual un orden completo y marca el retraso', function (): void {
    $suggester = new TripSequenceSuggester;
    $matrix = planningTripLineMatrix();
    // Imposible: la parada 3 está a 30 s de la base y su ventana cierra a los 5 s.
    $windows = [3 => TimeWindow::of(0.0, 5.0)];

    $order = $suggester->suggest($matrix, [1, 2, 3], $windows);
    $timeline = $suggester->timeline($matrix, $order, $windows);

    // Todas las posiciones llegan 25 s tarde a la parada 3: a igual retraso gana el recorrido más corto.
    expect($order)->toBe([1, 2, 3])
        ->and($timeline['late'])->toBe([false, false, true])
        ->and($timeline['lateness_seconds'])->toBe(25.0);
});

it('es determinista: la misma entrada da siempre el mismo orden', function (): void {
    $suggester = new TripSequenceSuggester;
    $matrix = planningTripLineMatrix();
    $windows = [2 => TimeWindow::of(0.0, 20.0)];

    $first = $suggester->suggest($matrix, [3, 2, 1], $windows, 5.0);
    $second = $suggester->suggest($matrix, [3, 2, 1], $windows, 5.0);

    expect($first)->toBe($second)
        ->and($first)->toHaveCount(3);
});

it('con una sola parada o ninguna devuelve la entrada tal cual', function (): void {
    $suggester = new TripSequenceSuggester;

    expect($suggester->suggest(planningTripLineMatrix(), [2]))->toBe([2])
        ->and($suggester->suggest(planningTripLineMatrix(), []))->toBe([]);
});

it('la matriz en línea recta es simétrica, con diagonal en cero y tiempo = distancia / velocidad', function (): void {
    $matrix = (new StraightLineMatrixBuilder)->build(
        [GeoPoint::of(-16.5, -68.15), GeoPoint::of(-16.5, -68.12)],
        speedKmh: 36.0,
        detourFactor: 1.5,
    );

    $straight = GeoPoint::of(-16.5, -68.15)->haversineMeters(GeoPoint::of(-16.5, -68.12));

    expect($matrix->size())->toBe(2)
        ->and($matrix->distanceMeters(0, 0))->toBe(0.0)
        ->and($matrix->distanceMeters(0, 1))->toBe($matrix->distanceMeters(1, 0))
        ->and(round($matrix->distanceMeters(0, 1), 3))->toBe(round($straight * 1.5, 3))
        // 36 km/h = 10 m/s.
        ->and(round($matrix->durationSeconds(0, 1), 3))->toBe(round($straight * 1.5 / 10.0, 3));
});

it('ancla la hora elegida por el cliente al día del viaje en la zona horaria del negocio', function (): void {
    $timezone = new DateTimeZone('America/La_Paz');
    $window = CustomerTimeWindow::on('2031-03-12', '08:00', '10:00', $timezone);

    expect($window)->not->toBeNull()
        ->and($window?->toArray())->toBe([
            'from' => '2031-03-12T08:00:00-04:00',
            'to' => '2031-03-12T10:00:00-04:00',
        ])
        ->and($window?->label())->toBe('08:00–10:00');
});

it('expresa la ventana en segundos desde la salida sin importar la zona en que venga la salida', function (): void {
    $window = CustomerTimeWindow::on('2031-03-12', '08:00', '10:00', new DateTimeZone('America/La_Paz'));
    // 07:30 en La Paz, expresado en UTC.
    $departAt = new DateTimeImmutable('2031-03-12T11:30:00+00:00');

    $relative = $window?->relativeTo($departAt);

    expect($relative?->earliestSeconds)->toBe(1800.0)
        ->and($relative?->latestSeconds)->toBe(9000.0);
});

it('una ventana ya cerrada a la salida queda instantánea: cualquier llegada cuenta tarde', function (): void {
    $window = CustomerTimeWindow::on('2031-03-12', '05:00', '06:00', new DateTimeZone('America/La_Paz'));
    $relative = $window?->relativeTo(new DateTimeImmutable('2031-03-12T07:30:00-04:00'));

    expect($relative?->earliestSeconds)->toBe(0.0)
        ->and($relative?->latestSeconds)->toBe(0.0)
        ->and($relative?->latenessAt(60.0))->toBe(60.0);
});

it('sin las dos horas válidas no hay ventana', function (?string $start, ?string $end): void {
    expect(CustomerTimeWindow::on('2031-03-12', $start, $end, new DateTimeZone('America/La_Paz')))->toBeNull();
})->with([
    'sin horas' => [null, null],
    'media ventana' => ['08:00', null],
    'formato inválido' => ['8am', '10:00'],
    'cierra antes de abrir' => ['12:00', '09:00'],
]);

it('la hora de salida por defecto es la configurada, en la zona horaria del negocio', function (): void {
    $settings = new TripBuilderSettings(timezone: 'America/La_Paz', defaultDepartTime: '07:30');

    expect($settings->departAt('2031-03-12')->format(DateTimeInterface::ATOM))->toBe('2031-03-12T07:30:00-04:00')
        ->and($settings->departAt('2031-03-12', '2031-03-12T09:15:00-04:00')->format(DateTimeInterface::ATOM))
        ->toBe('2031-03-12T09:15:00-04:00')
        ->and($settings->serviceSeconds())->toBe(600.0);
});
