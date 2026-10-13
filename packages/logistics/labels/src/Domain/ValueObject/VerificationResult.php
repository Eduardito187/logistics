<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Resultado de verificar la firma de una etiqueta.
 *
 * Es un resultado y no una excepción: en el andén una etiqueta falsa, editada o firmada con
 * una clave retirada es un caso de negocio que la app muestra en rojo, no un error del sistema.
 */
enum VerificationResult: string
{
    case Valid = 'valid';

    /** La firma no corresponde al contenido: etiqueta editada o fabricada. */
    case InvalidSignature = 'invalid_signature';

    /** El id de clave ("k") no está entre las claves públicas conocidas. */
    case UnknownKey = 'unknown_key';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }
}
