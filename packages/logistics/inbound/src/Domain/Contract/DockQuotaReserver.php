<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Contract;

use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;

/**
 * Puerto para reservar un cupo de ANDÉN DE RECEPCIÓN al agendar un ASN.
 *
 * Mantiene a inbound EXTRAÍBLE: el dominio no conoce `capacity`. El adaptador por defecto (Infrastructure)
 * despacha el `ConsumeCapacityCommand` de capacity por el `CommandBus` con el alcance del almacén (node) y
 * la clave natural almacén + día + franja. Si capacity aún no expone el eje de recepción, un doble de
 * prueba implementa este puerto y el flujo núcleo (announce → arrive → receipt → label) sigue testeable.
 *
 * Debe correr dentro de la MISMA transacción que el agendamiento (la abre el CommandBus): si el cupo está
 * lleno, lanza y todo el agendamiento se revierte.
 */
interface DockQuotaReserver
{
    public function reserve(string $warehouseId, AppointmentWindow $window): void;
}
