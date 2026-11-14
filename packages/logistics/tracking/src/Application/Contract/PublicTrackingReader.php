<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;

/**
 * Puerto de LECTURA del enlace público por token: devuelve la foto (estado del enlace + vista pública).
 *
 * Es la costura del read-side público: las consultas públicas dependen de este contrato y no saben si
 * la foto sale de la base, de un caché o —el día que el read-side se extraiga— de otro servicio. Nunca
 * lanza por un token inválido o inexistente: responde una foto `not_found`.
 */
interface PublicTrackingReader
{
    public function read(string $token): PublicTrackingSnapshot;
}
