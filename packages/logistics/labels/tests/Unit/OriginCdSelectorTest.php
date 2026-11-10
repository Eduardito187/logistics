<?php

declare(strict_types=1);

use Logistics\Labels\Domain\Service\OriginCdSelector;

/**
 * Regla PURA de selección del CD de origen del QR (sin DB): departamento del nodo destino → cercanía por
 * coordenadas → prefijo de la referencia → default. Determinista y estable (el `org` no es contrato con la
 * app, pero debe ser reproducible).
 */
beforeEach(function (): void {
    $this->selector = new OriginCdSelector;
    $this->cds = [
        ['code' => 'CD-LP-01', 'department' => 'la_paz', 'lat' => -16.5000, 'lng' => -68.1500],
        ['code' => 'CD-SCZ-01', 'department' => 'santa_cruz', 'lat' => -17.7833, 'lng' => -63.1821],
        ['code' => 'CD-CBBA-01', 'department' => 'cochabamba', 'lat' => -17.3895, 'lng' => -66.1568],
    ];
    $this->prefixes = ['SCZ' => 'santa_cruz', 'LPZ' => 'la_paz', 'LP' => 'la_paz', 'CBBA' => 'cochabamba', 'CBB' => 'cochabamba'];
});

it('elige el CD del departamento del nodo destino (retiro en tienda)', function (): void {
    $code = $this->selector->select($this->cds, 'cochabamba', null, null, 'SCZ-1', $this->prefixes, 'CD-SCZ-01');

    expect($code)->toBe('CD-CBBA-01');
});

it('a domicilio sin nodo: elige el CD más cercano por coordenadas', function (): void {
    // Un punto en Santa Cruz (sin departamento ni prefijo útil) debe caer en CD-SCZ-01.
    $code = $this->selector->select($this->cds, null, -17.80, -63.20, 'ORD-999', $this->prefixes, 'CD-LP-01');

    expect($code)->toBe('CD-SCZ-01');
});

it('sin coordenadas: infiere el departamento por el prefijo de la referencia (el más largo gana)', function (): void {
    $code = $this->selector->select($this->cds, null, null, null, 'CBBA-260012', $this->prefixes, 'CD-SCZ-01');

    expect($code)->toBe('CD-CBBA-01');
});

it('cae al default cuando nada resuelve', function (): void {
    $code = $this->selector->select($this->cds, null, null, null, 'ZZZ-1', $this->prefixes, 'CD-SCZ-01');

    expect($code)->toBe('CD-SCZ-01');
});

it('devuelve el default cuando no hay CDs en la red', function (): void {
    $code = $this->selector->select([], 'santa_cruz', -17.8, -63.2, 'SCZ-1', $this->prefixes, 'CD-FALLBACK');

    expect($code)->toBe('CD-FALLBACK');
});

it('prioriza el departamento del nodo por encima de las coordenadas', function (): void {
    // Nodo en La Paz pero coordenadas en Santa Cruz: manda el departamento del nodo destino.
    $code = $this->selector->select($this->cds, 'la_paz', -17.80, -63.20, 'SCZ-1', $this->prefixes, 'CD-SCZ-01');

    expect($code)->toBe('CD-LP-01');
});
