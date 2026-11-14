<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Una validación crítica previa al deploy (`logistics:pre-deploy`) no pasó: el comando la captura, imprime
 * el motivo y sale con código 1 para detener el rollout.
 */
final class PreDeployCheckFailed extends RuntimeException {}
