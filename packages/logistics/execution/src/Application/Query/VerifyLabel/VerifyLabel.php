<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\VerifyLabel;

use Logistics\Shared\Application\Query\Query;

/**
 * Verifica la firma de una etiqueta QR escaneada en terreno (contraverificación del servidor).
 *
 * El payload canónico es exactamente lo que se firmó (contrato con la app Flutter); la firma viene en
 * base64url y keyId es el campo "k" del QR.
 */
final readonly class VerifyLabel implements Query
{
    public function __construct(
        public string $canonicalPayload,
        public string $signature,
        public int $keyId,
    ) {}
}
