<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Model;

use Logistics\Labels\Domain\Exception\InvalidPrinterException;
use Logistics\Labels\Domain\ValueObject\PrinterEndpoint;
use Logistics\Labels\Domain\ValueObject\PrinterId;
use Logistics\Labels\Domain\ValueObject\PrinterLanguage;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Impresora de red registrada centralmente para una sede (nodo de red).
 *
 * Existe porque el teléfono no siempre puede descubrirla (WiFi y cable en redes distintas): se registra una
 * vez con su host/puerto y la app la ofrece. `nodeId` es una referencia opaca al nodo de Network (null =
 * compartida, visible para todas las sedes). Una impresora inactiva no se ofrece a la app.
 */
final class Printer extends AggregateRoot
{
    private function __construct(
        private readonly PrinterId $id,
        private string $name,
        private ?string $model,
        private PrinterEndpoint $endpoint,
        private PrinterLanguage $language,
        private ?string $nodeId,
        private ?string $location,
        private bool $active,
    ) {}

    public static function register(
        PrinterId $id,
        string $name,
        ?string $model,
        PrinterEndpoint $endpoint,
        PrinterLanguage $language,
        ?string $nodeId,
        ?string $location,
        bool $active = true,
    ): self {
        return new self(
            $id,
            self::requireName($name),
            self::optional($model),
            $endpoint,
            $language,
            self::optional($nodeId),
            self::optional($location),
            $active,
        );
    }

    /**
     * Rehidrata desde persistencia sin re-validar reglas de alta.
     */
    public static function reconstitute(
        PrinterId $id,
        string $name,
        ?string $model,
        PrinterEndpoint $endpoint,
        PrinterLanguage $language,
        ?string $nodeId,
        ?string $location,
        bool $active,
    ): self {
        return new self($id, $name, $model, $endpoint, $language, $nodeId, $location, $active);
    }

    /**
     * Reemplaza los datos editables (alta/baja/edición del admin: cambiar la IP se refleja en la app).
     */
    public function update(
        string $name,
        ?string $model,
        PrinterEndpoint $endpoint,
        PrinterLanguage $language,
        ?string $nodeId,
        ?string $location,
        bool $active,
    ): void {
        $this->name = self::requireName($name);
        $this->model = self::optional($model);
        $this->endpoint = $endpoint;
        $this->language = $language;
        $this->nodeId = self::optional($nodeId);
        $this->location = self::optional($location);
        $this->active = $active;
    }

    public function id(): PrinterId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function endpoint(): PrinterEndpoint
    {
        return $this->endpoint;
    }

    public function language(): PrinterLanguage
    {
        return $this->language;
    }

    public function nodeId(): ?string
    {
        return $this->nodeId;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    private static function requireName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw InvalidPrinterException::emptyName();
        }

        return $name;
    }

    private static function optional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
