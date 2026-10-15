<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Ingestion;

use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;

/**
 * Resultado del parseo de UNA fila de la planilla de carga manual/Excel (plantilla F0-SRC-03).
 *
 * Una fila es válida (trae un comando de ingreso listo) o inválida (trae el motivo del error). El
 * parser nunca lanza por una fila mala: la marca como inválida y sigue, para poder reportar todos los
 * errores de un archivo de una sola pasada.
 */
final readonly class ParsedShipmentRow
{
    private function __construct(
        public int $rowNumber,
        public ?ReceiveShipment $command,
        public ?string $error,
    ) {}

    /**
     * Fila válida: trae el comando de ingreso ya normalizado.
     */
    public static function valid(int $rowNumber, ReceiveShipment $command): self
    {
        return new self($rowNumber, $command, null);
    }

    /**
     * Fila inválida: trae el número de fila (1-based del archivo) y el motivo del rechazo.
     */
    public static function invalid(int $rowNumber, string $error): self
    {
        return new self($rowNumber, null, $error);
    }

    public function isValid(): bool
    {
        return $this->command !== null;
    }
}
