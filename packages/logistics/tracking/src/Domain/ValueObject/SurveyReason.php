<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

/**
 * Motivo opcional de una calificación baja: por qué el cliente quedó insatisfecho.
 *
 * Es un vocabulario cerrado para poder agregarlo/analizarlo (a diferencia del comentario libre). Solo
 * tiene sentido cuando la calificación es baja; en una calificación alta queda null.
 */
enum SurveyReason: string
{
    /** Llegó más tarde de lo prometido. */
    case Late = 'LATE';

    /** El producto llegó dañado. */
    case Damaged = 'DAMAGED';

    /** Faltaron bultos o productos del pedido. */
    case Incomplete = 'INCOMPLETE';

    /** Problema con el repartidor (trato, actitud). */
    case Courier = 'COURIER';

    /** Otro motivo (se detalla en el comentario). */
    case Other = 'OTHER';
}
