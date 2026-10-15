<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Resultado de verificar la firma de una etiqueta en el escaneo de terreno.
 *
 * Es un resultado de negocio, no una excepción: en el andén una etiqueta falsa, editada o firmada
 * con una clave retirada es un caso que la app muestra en rojo, no un error del sistema. Espeja el
 * contrato de la app Flutter (verificación offline con el bundle de claves públicas).
 */
enum LabelVerification: string
{
    /** Firma válida: la etiqueta corresponde a su contenido y a una clave vigente. */
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
