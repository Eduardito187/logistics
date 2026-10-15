<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto de promesa de entrega: datos de franja inválidos, una
 * transición de estado no permitida o una reserva vencida al confirmar.
 *
 * Lleva además un `kind` opcional para que Presentation mapee el error a un HTTP preciso sin inspeccionar
 * el texto del mensaje: la reserva del cliente debe responder 409 cuando la franja ya está tomada y 422
 * cuando el día no es despachable, dos situaciones que el canal necesita distinguir. `because()` mantiene
 * el comportamiento previo (sin kind: regla de negocio genérica, 422).
 */
final class PromiseException extends DomainException
{
    /** Franja sin cupo: ya la tomó otro cliente o se agotó (conflicto, 409). */
    public const string KIND_SLOT_TAKEN = 'slot_taken';

    /** Día no despachable: bloqueado (feriado/otro motivo) o fuera del horizonte activo (422). */
    public const string KIND_DAY_UNAVAILABLE = 'day_unavailable';

    /** Recurso inexistente (envío/promesa no encontrados, 404). */
    public const string KIND_NOT_FOUND = 'not_found';

    private function __construct(string $message, private readonly ?string $kind = null)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function slotTaken(string $reason): self
    {
        return new self($reason, self::KIND_SLOT_TAKEN);
    }

    public static function dayUnavailable(string $reason): self
    {
        return new self($reason, self::KIND_DAY_UNAVAILABLE);
    }

    public static function notFound(string $reason): self
    {
        return new self($reason, self::KIND_NOT_FOUND);
    }

    /**
     * Clasificación del error para el mapeo HTTP de Presentation, o null si es una regla genérica.
     */
    public function kind(): ?string
    {
        return $this->kind;
    }
}
