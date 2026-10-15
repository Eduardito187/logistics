<?php

declare(strict_types=1);

namespace App\Http\Problem;

/**
 * Catálogo de códigos de error de la API (contrato estable con los clientes).
 *
 * El `value` de cada caso es el código legible por máquina que viaja en el miembro
 * `code` del problema RFC 7807 y que forma la URI `type`. Los clientes (en especial la
 * app Flutter) deben ramificar su lógica por este código, NO por el texto `title`, que
 * es orientativo y puede ajustarse sin romper el contrato.
 *
 * Cada código fija su estado HTTP y su título por defecto, de modo que las tres puertas
 * (`/app/*`, `/channels/*`, `/integrations/*`) emitan errores uniformes.
 */
enum ErrorCode: string
{
    // 400 — petición mal formada.
    case BadRequest = 'bad_request';
    case InvalidIdempotencyKey = 'invalid_idempotency_key';

    // 401 — autenticación.
    case Unauthenticated = 'unauthenticated';
    case MissingCredentials = 'missing_credentials';
    case InvalidCredentials = 'invalid_credentials';
    case DeviceRevoked = 'device_revoked';

    // 403 — autorización.
    case Forbidden = 'forbidden';
    case InsufficientRole = 'insufficient_role';
    case InsufficientScope = 'insufficient_scope';

    // 404 / 405 — ruteo.
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';

    // 409 — conflicto de estado.
    case Conflict = 'conflict';
    case IdempotencyKeyInProgress = 'idempotency_key_in_progress';
    case IdempotencyKeyReused = 'idempotency_key_reused';

    // 413 / 415 — payload.
    case PayloadTooLarge = 'payload_too_large';
    case UnsupportedMediaType = 'unsupported_media_type';

    // 422 — validación / regla de negocio.
    case ValidationFailed = 'validation_failed';

    // 429 — límite de tasa.
    case RateLimited = 'rate_limited';

    // 5xx — servidor.
    case ServerError = 'server_error';
    case ServiceUnavailable = 'service_unavailable';

    /**
     * Estado HTTP que corresponde a este código.
     */
    public function status(): int
    {
        return match ($this) {
            self::BadRequest, self::InvalidIdempotencyKey => 400,
            self::Unauthenticated, self::MissingCredentials, self::InvalidCredentials, self::DeviceRevoked => 401,
            self::Forbidden, self::InsufficientRole, self::InsufficientScope => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict, self::IdempotencyKeyInProgress, self::IdempotencyKeyReused => 409,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::ValidationFailed => 422,
            self::RateLimited => 429,
            self::ServerError => 500,
            self::ServiceUnavailable => 503,
        };
    }

    /**
     * Título por defecto, corto y estable, legible por humanos (RFC 7807 §3.1).
     */
    public function title(): string
    {
        return match ($this) {
            self::BadRequest => 'Bad request',
            self::InvalidIdempotencyKey => 'Invalid idempotency key',
            self::Unauthenticated => 'Unauthenticated',
            self::MissingCredentials => 'Missing credentials',
            self::InvalidCredentials => 'Invalid credentials',
            self::DeviceRevoked => 'Device revoked',
            self::Forbidden => 'Forbidden',
            self::InsufficientRole => 'Insufficient role',
            self::InsufficientScope => 'Insufficient scope',
            self::NotFound => 'Resource not found',
            self::MethodNotAllowed => 'Method not allowed',
            self::Conflict => 'Conflict',
            self::IdempotencyKeyInProgress => 'Idempotency key in progress',
            self::IdempotencyKeyReused => 'Idempotency key reused',
            self::PayloadTooLarge => 'Payload too large',
            self::UnsupportedMediaType => 'Unsupported media type',
            self::ValidationFailed => 'Validation failed',
            self::RateLimited => 'Too many requests',
            self::ServerError => 'Internal server error',
            self::ServiceUnavailable => 'Service unavailable',
        };
    }

    /**
     * Resuelve el código que mejor representa un estado HTTP arbitrario (lo usa el
     * renderizador para excepciones HTTP genéricas que no traen un código propio).
     */
    public static function fromStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            413 => self::PayloadTooLarge,
            415 => self::UnsupportedMediaType,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            503 => self::ServiceUnavailable,
            default => $status >= 500 ? self::ServerError : self::BadRequest,
        };
    }
}
