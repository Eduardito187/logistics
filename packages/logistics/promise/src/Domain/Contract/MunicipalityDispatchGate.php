<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use DateTimeImmutable;

/**
 * Puerto hacia el contexto de capacidad que responde si un (municipio, fecha) es DESPACHABLE: ni
 * bloqueado (feriado u otro motivo, propio del municipio o global) ni fuera del horizonte activo de
 * cupos del municipio.
 *
 * Lo usa la reserva del cliente para distinguir, ANTES de tomar cupo, un día no disponible (se rechaza
 * con "día no disponible") de un cupo simplemente agotado (se rechaza con "franja ya tomada"): dos
 * respuestas distintas que el canal necesita diferenciar. `municipalityId` null consulta el alcance
 * global. El cruce es por el bus y por primitivos; el dominio no importa `logistics/capacity`.
 */
interface MunicipalityDispatchGate
{
    public function isDispatchable(?string $municipalityId, DateTimeImmutable $date): bool;
}
