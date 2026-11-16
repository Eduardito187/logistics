<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Contract;

/**
 * Lado lectura (CQRS) de las tripulaciones: quién maneja qué vehículo y con qué ayudantes en una fecha,
 * con los nombres y la placa ya resueltos para mostrar. También la baja de una asignación.
 *
 * @phpstan-type CrewRow array{id: string, assembled_for: string, vehicle_id: string, vehicle_plate: string|null, driver_id: string, driver_name: string|null, helpers: list<array{id: string, name: string|null}>, shift_starts_at: string|null, shift_ends_at: string|null}
 */
interface CrewDirectory
{
    /**
     * Tripulaciones que cumplen los filtros (todos opcionales), de la más reciente a la más vieja.
     *
     * @return array{items: list<CrewRow>, total: int}
     */
    public function list(?string $date, ?string $vehicleId, ?string $driverId, int $limit, int $offset): array;

    /**
     * Tripulaciones en las que un AYUDANTE figura (su id dentro de `helper_ids`) en la fecha, de la más
     * reciente a la más vieja. Mismo shape que `list`; filtra a nivel de base por pertenencia del ayudante.
     *
     * @return array{items: list<CrewRow>, total: int}
     */
    public function listForHelper(?string $date, string $helperId, int $limit, int $offset): array;

    /**
     * Una tripulación por id, o null.
     *
     * @return CrewRow|null
     */
    public function find(string $crewId): ?array;

    /** Quita la asignación. Devuelve false si no existía. */
    public function remove(string $crewId): bool;
}
