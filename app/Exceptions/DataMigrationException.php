<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Una migración de datos detectó una inconsistencia (por ejemplo, el conteo de filas copiadas no coincide
 * con el de la tabla original) y aborta para que la transacción se revierta y los datos queden intactos.
 */
final class DataMigrationException extends RuntimeException {}
