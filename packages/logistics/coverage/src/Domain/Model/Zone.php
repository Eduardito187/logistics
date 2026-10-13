<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Model;

use Logistics\Coverage\Domain\Event\ZoneDefined;
use Logistics\Coverage\Domain\Event\ZoneRulesUpdated;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Zona de cobertura: una superficie (polígono) con reglas de operación propias, colgada de un área
 * administrativa de la jerarquía (ciudad, municipio…).
 *
 * Es la raíz de agregado del contexto Cobertura. Registra eventos de dominio que Application despacha
 * al outbox dentro de la misma transacción; el dominio nunca publica por sí mismo.
 */
final class Zone extends AggregateRoot
{
    private function __construct(
        private readonly ZoneId $id,
        private string $name,
        private readonly ?GeoAreaId $parentAreaId,
        private readonly Polygon $polygon,
        private ZoneRules $rules,
        private bool $active,
    ) {}

    /**
     * Named constructor: define una zona nueva y registra el evento ZoneDefined.
     */
    public static function define(
        ZoneId $id,
        string $name,
        ?GeoAreaId $parentAreaId,
        Polygon $polygon,
        ZoneRules $rules,
    ): self {
        $zone = new self($id, $name, $parentAreaId, $polygon, $rules, true);
        $zone->recordEvent(new ZoneDefined(
            $id->toString(),
            $name,
            $parentAreaId?->toString(),
            $polygon->toGeoJson(),
            $rules->toArray(),
        ));

        return $zone;
    }

    /**
     * Reconstituye una zona desde persistencia, sin registrar eventos.
     */
    public static function reconstitute(
        ZoneId $id,
        string $name,
        ?GeoAreaId $parentAreaId,
        Polygon $polygon,
        ZoneRules $rules,
        bool $active,
    ): self {
        return new self($id, $name, $parentAreaId, $polygon, $rules, $active);
    }

    public function updateRules(ZoneRules $rules): void
    {
        $this->rules = $rules;
        $this->recordEvent(new ZoneRulesUpdated($this->id->toString(), $rules->toArray()));
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function id(): ZoneId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function parentAreaId(): ?GeoAreaId
    {
        return $this->parentAreaId;
    }

    public function polygon(): Polygon
    {
        return $this->polygon;
    }

    public function rules(): ZoneRules
    {
        return $this->rules;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
