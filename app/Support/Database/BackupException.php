<?php

declare(strict_types=1);

namespace App\Support\Database;

use RuntimeException;

/**
 * Error del backup/restore de la base (dump, restore, swap, manifest, bundle o cliente pg). Extiende
 * RuntimeException para que cualquier `catch (RuntimeException)`/`catch (Throwable)` existente lo siga
 * atrapando; tenerla dedicada permite distinguir los fallos de backup del resto.
 */
final class BackupException extends RuntimeException {}
