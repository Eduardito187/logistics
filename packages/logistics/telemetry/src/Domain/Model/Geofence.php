<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Model;

use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Telemetry\Domain\Event\GeofenceActivated;
use Logistics\Telemetry\Domain\Event\GeofenceDeactivated;
use Logistics\Telemetry\Domain\Event\GeofenceDefined;
use Logistics\Telemetry\Domain\Event\GeofenceRedrawn;
use Logistics\Telemetry\Domain\Event\GeofenceRenamed;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Polygon;

/**
 * Geocerca: un polígono con nombre y tipo que la operación usa para evaluar el comportamiento de los
 * vehículos (entrar a una zona prohibida, estar en un centro de distribución, etc.).
 *
 * El agregado decide su ciclo de vida (renombrar, redibujar, activar/desactivar) y expone `contains`
 * para la detección de mal uso, que solo cuenta si la geocerca está activa. La prueba de pertenencia
 * es PHP puro (ray casting), delegada en el VO Polygon; el agregado no conoce PostGIS ni la base.
 */
final class Geofence extends AggregateRoot
{
    private function __construct(
        private readonly GeofenceId $id,
        private string $name,
        private GeofenceType $type,
        private Polygon $polygon,
        private bool $active,
        private ?string $nodeRef,
    ) {}

    /**
     * Define una geocerca nueva. Arranca activa.
     */
    public static function define(
        GeofenceId $id,
        string $name,
        GeofenceType $type,
        Polygon $polygon,
        ?string $nodeRef = null,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw TelemetryException::because('El nombre de la geocerca no puede estar vacío.');
        }
        $nodeRef = self::normalizeNodeRef($nodeRef);

        $geofence = new self($id, $name, $type, $polygon, true, $nodeRef);
        $geofence->recordEvent(new GeofenceDefined(
            $id->toString(),
            $name,
            $type->value,
            $nodeRef,
        ));

        return $geofence;
    }

    /**
     * Reconstituye una geocerca desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        GeofenceId $id,
        string $name,
        GeofenceType $type,
        Polygon $polygon,
        bool $active,
        ?string $nodeRef,
    ): self {
        return new self($id, $name, $type, $polygon, $active, $nodeRef);
    }

    /**
     * Renombra la geocerca. Idempotente: si el nombre no cambia, no hace nada ni graba evento.
     */
    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw TelemetryException::because('El nombre de la geocerca no puede estar vacío.');
        }
        if ($name === $this->name) {
            return;
        }
        $this->name = $name;
        $this->recordEvent(new GeofenceRenamed($this->id->toString(), $name));
    }

    /**
     * Redibuja el polígono de la geocerca.
     */
    public function redraw(Polygon $polygon): void
    {
        $this->polygon = $polygon;
        $this->recordEvent(new GeofenceRedrawn($this->id->toString(), count($polygon->vertices)));
    }

    /**
     * Activa la geocerca. Idempotente: si ya está activa, no hace nada ni graba evento.
     */
    public function activate(): void
    {
        if ($this->active) {
            return;
        }
        $this->active = true;
        $this->recordEvent(new GeofenceActivated($this->id->toString()));
    }

    /**
     * Desactiva la geocerca. Idempotente: si ya está inactiva, no hace nada ni graba evento.
     */
    public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->recordEvent(new GeofenceDeactivated($this->id->toString()));
    }

    /**
     * ¿El punto cae dentro de esta geocerca? Solo si está activa; una geocerca inactiva no contiene nada.
     */
    public function contains(GeoPoint $point): bool
    {
        return $this->active && $this->polygon->contains($point);
    }

    private static function normalizeNodeRef(?string $nodeRef): ?string
    {
        if ($nodeRef === null) {
            return null;
        }
        $nodeRef = trim($nodeRef);

        return $nodeRef === '' ? null : $nodeRef;
    }

    public function id(): GeofenceId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): GeofenceType
    {
        return $this->type;
    }

    public function polygon(): Polygon
    {
        return $this->polygon;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function nodeRef(): ?string
    {
        return $this->nodeRef;
    }
}
