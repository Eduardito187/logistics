<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * Conversión entre instantes del dominio y las columnas `timestamp` (sin zona) de la base.
 *
 * Las columnas created_at de `notifications` las escribe Laravel en la zona de la app
 * (date_default_timezone_get()), con precisión de segundos. El estado de lectura se guarda con el mismo
 * criterio para que las comparaciones `created_at <= read_up_to` sean coherentes.
 */
final class NotificationTimestamps
{
    public static function toColumn(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(self::zone())->format('Y-m-d H:i:s');
    }

    public static function fromColumn(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, self::zone());
        } catch (Exception) {
            return null;
        }
    }

    public static function toIso(?DateTimeImmutable $instant): ?string
    {
        return $instant?->format(DateTimeInterface::ATOM);
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }
}
