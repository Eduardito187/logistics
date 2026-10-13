<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;

/**
 * Proyección de lectura de un ayudante para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class HelperView
{
    /**
     * @param list<array{type: string, expires_on: string}> $documents
     */
    private function __construct(
        public string $id,
        public string $name,
        public ?string $phone,
        public array $documents,
        public bool $active,
    ) {}

    public static function fromDomain(Helper $helper): self
    {
        return new self(
            $helper->id()->toString(),
            $helper->name(),
            $helper->phone(),
            array_values(array_map(static fn (DocumentExpiry $document) => $document->toArray(), $helper->documents())),
            $helper->isActive(),
        );
    }

    /**
     * @return array{id: string, name: string, phone: string|null, documents: list<array{type: string, expires_on: string}>, active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'documents' => $this->documents,
            'active' => $this->active,
        ];
    }
}
