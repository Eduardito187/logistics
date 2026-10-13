<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Exception;

use RuntimeException;

/**
 * Base de los errores de regla de negocio. Cada contexto extiende la suya para mensajes propios.
 */
abstract class DomainException extends RuntimeException {}
