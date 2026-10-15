<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ExpireDueReservations;

use Logistics\Shared\Application\Command\Command;

/**
 * Barrido en lote: expira todas las reservas de promesa cuya ventana ya venció y libera su cupo.
 *
 * No lleva datos: el "ahora" lo toma el handler del reloj del dominio. Lo dispara el scheduler cada
 * minuto (como el relay del outbox) para que ningún cupo quede retenido por una reserva abandonada.
 */
final readonly class ExpireDueReservations implements Command {}
