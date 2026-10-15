<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Contract;

use Logistics\Execution\Domain\ValueObject\LabelVerification;

/**
 * Puerta de verificación de la firma de una etiqueta QR en el escaneo de terreno (P1-EXE-12).
 *
 * El dominio de Ejecución define esta puerta y la usa por contrato: no se acopla al contexto Labels.
 * La implementación real delega en el verificador Ed25519 de Labels (claves públicas vigentes); el
 * stub de Infrastructure permite operar y testear sin esa dependencia. La app Flutter hace la misma
 * verificación offline con el bundle de claves públicas; este endpoint es la contraverificación del
 * servidor.
 */
interface LabelVerificationGateway
{
    /**
     * Verifica la firma de una etiqueta a partir de su payload canónico, la firma y el id de clave.
     *
     * @param string $canonicalPayload JSON canónico del QR (lo que se firmó).
     * @param string $signature        Firma en base64url del payload.
     * @param int    $keyId            Id de la clave pública con la que se firmó (campo "k" del QR).
     */
    public function verify(string $canonicalPayload, string $signature, int $keyId): LabelVerification;
}
