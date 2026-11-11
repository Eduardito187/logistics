<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Model;

use Logistics\Labels\Domain\ValueObject\PrinterDetails;
use Logistics\Labels\Domain\ValueObject\PrinterEndpoint;
use Logistics\Labels\Domain\ValueObject\PrinterId;
use Logistics\Labels\Domain\ValueObject\PrinterLanguage;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Impresora de red registrada centralmente para una sede (nodo de red).
 *
 * Existe porque el teléfono no siempre puede descubrirla (WiFi y cable en redes distintas): se registra una
 * vez con su host/puerto y la app la ofrece. Los datos editables viven en {@see PrinterDetails} (VO que
 * valida y normaliza). Una impresora inactiva no se ofrece a la app.
 */
final class Printer extends AggregateRoot
{
    private function __construct(
        private readonly PrinterId $id,
        private PrinterDetails $details,
        private bool $active,
    ) {}

    public static function register(PrinterId $id, PrinterDetails $details, bool $active = true): self
    {
        return new self($id, $details, $active);
    }

    /**
     * Rehidrata desde persistencia.
     */
    public static function reconstitute(PrinterId $id, PrinterDetails $details, bool $active): self
    {
        return new self($id, $details, $active);
    }

    /**
     * Reemplaza los datos editables (alta/baja/edición del admin: cambiar la IP se refleja en la app).
     */
    public function update(PrinterDetails $details, bool $active): void
    {
        $this->details = $details;
        $this->active = $active;
    }

    public function id(): PrinterId
    {
        return $this->id;
    }

    public function details(): PrinterDetails
    {
        return $this->details;
    }

    public function name(): string
    {
        return $this->details->name;
    }

    public function model(): ?string
    {
        return $this->details->model;
    }

    public function endpoint(): PrinterEndpoint
    {
        return $this->details->endpoint;
    }

    public function language(): PrinterLanguage
    {
        return $this->details->language;
    }

    public function nodeId(): ?string
    {
        return $this->details->nodeId;
    }

    public function location(): ?string
    {
        return $this->details->location;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
