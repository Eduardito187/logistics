<?php

declare(strict_types=1);

namespace Logistics\Settings\Application\ReadModel;

use Logistics\Settings\Domain\SettingVersion;

/**
 * Vista de lectura de una versión de configuración: forma primitiva lista para serializar (JSON/admin).
 *
 * No expone objetos de dominio; se arma desde una SettingVersion con `fromDomain()`.
 */
final readonly class SettingView
{
    /**
     * @param array<string, mixed> $value
     */
    public function __construct(
        public string $id,
        public string $key,
        public int $version,
        public array $value,
        public string $effectiveFrom,
        public string $createdAt,
        public ?string $createdBy,
    ) {}

    public static function fromDomain(SettingVersion $version): self
    {
        return new self(
            $version->id->toString(),
            $version->key->value,
            $version->version,
            $version->value,
            $version->effectiveFrom->format(DATE_ATOM),
            $version->createdAt->format(DATE_ATOM),
            $version->createdBy,
        );
    }

    /**
     * @return array{id: string, key: string, version: int, value: array<string, mixed>, effective_from: string, created_at: string, created_by: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'version' => $this->version,
            'value' => $this->value,
            'effective_from' => $this->effectiveFrom,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
        ];
    }
}
