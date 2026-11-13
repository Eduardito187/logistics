<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Service\WindowSelector;
use Logistics\Promise\Domain\ValueObject\CustomerTimePreference;
use Logistics\Promise\Domain\ValueObject\WindowCandidate;
use Logistics\Promise\Domain\ValueObject\WindowSelectionPolicy;

/*
| Servicio de dominio WindowSelector (Módulo 1). Prueban que elige la ventana de forma determinista:
| honra la preferencia del cliente (peso alto), balancea carga entre franjas, filtra las sin cupo y no
| depende del orden de entrada.
*/

$selector = new WindowSelector;

it('sin candidatas devuelve null', function () use ($selector): void {
    expect($selector->select([], CustomerTimePreference::none(), WindowSelectionPolicy::default()))->toBeNull();
});

it('si ninguna franja tiene cupo devuelve null', function () use ($selector): void {
    $candidates = [
        WindowCandidate::of('09:00-13:00', 0.0),
        WindowCandidate::of('14:00-18:00', 0.5),
    ];

    expect($selector->select($candidates, CustomerTimePreference::none(), WindowSelectionPolicy::default()))->toBeNull();
});

it('la preferencia del cliente gana sobre el balanceo de carga', function () use ($selector): void {
    // La tarde tiene mucho más cupo, pero el cliente prefiere la mañana: debe ganar la mañana.
    $candidates = [
        WindowCandidate::of('09:00-13:00', 1.0),
        WindowCandidate::of('14:00-18:00', 10.0),
    ];

    $choice = $selector->select($candidates, CustomerTimePreference::morning(), WindowSelectionPolicy::default());

    expect($choice)->not->toBeNull()
        ->and($choice->timeSlot)->toBe('09:00-13:00');
});

it('sin preferencia, el balanceo lleva a la franja con más cupo libre', function () use ($selector): void {
    $candidates = [
        WindowCandidate::of('09:00-13:00', 2.0),
        WindowCandidate::of('14:00-18:00', 10.0),
    ];

    $choice = $selector->select($candidates, CustomerTimePreference::none(), WindowSelectionPolicy::default());

    expect($choice->timeSlot)->toBe('14:00-18:00');
});

it('es determinista: no depende del orden de entrada de las candidatas', function () use ($selector): void {
    $ascending = [
        WindowCandidate::of('09:00-13:00', 2.0),
        WindowCandidate::of('14:00-18:00', 10.0),
    ];
    $descending = array_reverse($ascending);

    $first = $selector->select($ascending, CustomerTimePreference::none(), WindowSelectionPolicy::default());
    $second = $selector->select($descending, CustomerTimePreference::none(), WindowSelectionPolicy::default());

    expect($first->timeSlot)->toBe($second->timeSlot);
});

it('a igual puntaje desempata por la franja más temprana', function (): void {
    // Solo pesa "temprano": dos franjas con el mismo cupo empatan en pref y balance, gana la temprana.
    $onlyEarly = WindowSelectionPolicy::of(0.0, 0.0, 1.0);
    $candidates = [
        WindowCandidate::of('14:00-18:00', 5.0),
        WindowCandidate::of('09:00-13:00', 5.0),
    ];

    $choice = (new WindowSelector)->select($candidates, CustomerTimePreference::none(), $onlyEarly);

    expect($choice->timeSlot)->toBe('09:00-13:00');
});

it('descarta las franjas con cupo insuficiente y elige entre las factibles', function () use ($selector): void {
    $candidates = [
        WindowCandidate::of('09:00-13:00', 0.4),
        WindowCandidate::of('14:00-18:00', 3.0),
    ];

    $choice = $selector->select($candidates, CustomerTimePreference::none(), WindowSelectionPolicy::default());

    expect($choice->timeSlot)->toBe('14:00-18:00');
});
