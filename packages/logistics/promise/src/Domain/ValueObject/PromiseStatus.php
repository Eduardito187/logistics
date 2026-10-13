<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

/**
 * Estado de la promesa a lo largo de su ciclo de vida.
 *
 * `Quoted` → se calculó y ofreció una franja, sin reservar todavía.
 * `Reserved` → se reservó el cupo por una ventana de tiempo (ver ReservationWindow).
 * `Confirmed` → el canal confirmó dentro de la ventana; la entrega queda prometida en firme.
 * `Expired` → la ventana venció antes de confirmar; el cupo se libera.
 */
enum PromiseStatus: string
{
    /** Franja ofrecida, sin cupo reservado aún. */
    case Quoted = 'QUOTED';

    /** Cupo reservado temporalmente a la espera de confirmación. */
    case Reserved = 'RESERVED';

    /** Promesa confirmada dentro de la ventana de reserva. */
    case Confirmed = 'CONFIRMED';

    /** La ventana de reserva venció antes de confirmar; cupo liberado. */
    case Expired = 'EXPIRED';
}
