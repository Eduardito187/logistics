<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterDocument;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un documento de flota (con su vencimiento y, opcionalmente, su archivo S3).
 *
 * `ownerType` es uno de vehicle|driver|helper; `ownerId` es el UUID del dueño. Las fechas llegan como
 * texto Y-m-d; el handler las convierte y valida contra el VO del dominio.
 */
final readonly class RegisterDocument implements Command
{
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public string $documentType,
        public string $expiresOn,
        public ?string $issuedOn = null,
        public ?string $filePath = null,
        public ?string $note = null,
    ) {}
}
