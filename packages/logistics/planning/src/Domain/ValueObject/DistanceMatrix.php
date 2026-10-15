<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Matriz de distancias (metros) y tiempos (segundos) POR CARRETERA entre un conjunto ordenado de puntos.
 *
 * Por convención del motor de ruteo, el índice 0 es el ORIGEN (CD / depósito) y los índices 1..n son las
 * paradas. Es un dato puro, inmutable: la calcula Infrastructure (OSRM /table, o haversine al degradar) y
 * la consumen los algoritmos de dominio (Clarke-Wright, 2-opt) sin saber de dónde salió.
 *
 * Se guarda en forma primitiva (arrays de float) justamente para poder cachearla sin serializar objetos
 * (regla 7 de la guía del proyecto).
 */
final readonly class DistanceMatrix
{
    /**
     * @param list<list<float>> $distances metros entre i y j
     * @param list<list<float>> $durations segundos entre i y j
     */
    private function __construct(
        public array $distances,
        public array $durations,
    ) {}

    /**
     * @param list<list<float>> $distances
     * @param list<list<float>> $durations
     */
    public static function fromArrays(array $distances, array $durations): self
    {
        $size = count($distances);
        if ($size === 0) {
            throw PlanningException::because('la matriz de distancias no puede estar vacía');
        }
        if (count($durations) !== $size) {
            throw PlanningException::because('distancias y tiempos deben tener la misma cantidad de filas');
        }
        foreach ([$distances, $durations] as $grid) {
            foreach ($grid as $row) {
                if (count($row) !== $size) {
                    throw PlanningException::because('la matriz debe ser cuadrada');
                }
            }
        }

        return new self($distances, $durations);
    }

    public function size(): int
    {
        return count($this->distances);
    }

    public function distanceMeters(int $from, int $to): float
    {
        return $this->cell($this->distances, $from, $to);
    }

    public function durationSeconds(int $from, int $to): float
    {
        return $this->cell($this->durations, $from, $to);
    }

    /**
     * @param list<list<float>> $grid
     */
    private function cell(array $grid, int $from, int $to): float
    {
        if (!isset($grid[$from][$to])) {
            throw PlanningException::because("índice fuera de rango en la matriz: ({$from}, {$to})");
        }

        return $grid[$from][$to];
    }
}
