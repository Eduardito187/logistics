<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Labels;

use Logistics\Execution\Domain\Contract\LabelVerificationGateway;
use Logistics\Execution\Domain\ValueObject\LabelVerification;

/**
 * Implementación stub de la verificación de etiquetas, detrás del contrato LabelVerificationGateway.
 *
 * Permite que Ejecución opere y se pruebe sin acoplarse al contexto Labels: valida la forma (payload
 * y firma presentes) y que el id de clave esté entre las claves públicas conocidas por configuración.
 * No hace criptografía real; el integrador la sustituye por un adapter que delega en el verificador
 * Ed25519 de Labels (misma puerta, bind distinto), sin tocar el dominio ni la aplicación.
 *
 * @see LabelVerificationGateway
 */
final class StubLabelVerificationGateway implements LabelVerificationGateway
{
    /**
     * @param list<int> $knownKeyIds Ids de claves públicas vigentes (config logistics-execution).
     */
    public function __construct(
        private readonly array $knownKeyIds,
    ) {}

    public function verify(string $canonicalPayload, string $signature, int $keyId): LabelVerification
    {
        if (!in_array($keyId, $this->knownKeyIds, true)) {
            return LabelVerification::UnknownKey;
        }

        // Sin criptografía real: una firma o un payload vacíos se tratan como firma inválida. El
        // adapter real reemplaza esto por la verificación Ed25519 contra la clave pública del keyId.
        if (trim($canonicalPayload) === '' || trim($signature) === '') {
            return LabelVerification::InvalidSignature;
        }

        return LabelVerification::Valid;
    }
}
