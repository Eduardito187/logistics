<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\ListDeliveredAwaitingRating;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;

/**
 * Lista los envíos entregados desde `since` que ya cumplieron `minAgeMinutes` desde la entrega (default
 * 1 h: que el cliente haya abierto la caja), tienen email y todavía no fueron invitados ni calificaron.
 * Es la entrada del cron horario de la encuesta; `limit` acota el lote (1..500).
 */
final readonly class ListDeliveredAwaitingRating implements Query
{
    public const int DEFAULT_MIN_AGE_MINUTES = 60;

    public const int MAX_LIMIT = 500;

    public function __construct(
        public DateTimeImmutable $since,
        public int $limit = 100,
        public int $minAgeMinutes = self::DEFAULT_MIN_AGE_MINUTES,
    ) {}
}
