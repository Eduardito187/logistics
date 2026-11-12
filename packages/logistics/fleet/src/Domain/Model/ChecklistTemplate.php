<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use Logistics\Fleet\Domain\Event\ChecklistTemplateDefined;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\ChecklistEvaluation;
use Logistics\Fleet\Domain\ValueObject\ChecklistItemCriticality;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateId;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateItem;
use Logistics\Fleet\Domain\ValueObject\PreTripChecklist;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Plantilla del checklist pre-salida: qué ítems revisa el chofer, en qué orden, de qué categoría, con
 * qué criticidad y si exigen foto. Vive en el servidor (antes estaba fija en la app) y se administra
 * desde el admin.
 *
 * `vehicleType` es opcional: null = plantilla por defecto de la flota; un valor (p. ej. "truck", "van")
 * permite una plantilla específica por tipo de vehículo. Evalúa un checklist ejecutado y decide si el
 * vehículo está apto para despachar: un ítem CRÍTICO reportado como fallido lo deja NO APTO.
 */
final class ChecklistTemplate extends AggregateRoot
{
    /**
     * @param list<ChecklistTemplateItem> $items
     */
    private function __construct(
        private readonly ChecklistTemplateId $id,
        private string $name,
        private ?string $vehicleType,
        private bool $active,
        private array $items,
    ) {}

    /**
     * Define (alta o reemplazo completo) una plantilla. Valida que tenga ítems y claves únicas.
     *
     * @param list<ChecklistTemplateItem> $items
     */
    public static function define(
        ChecklistTemplateId $id,
        string $name,
        ?string $vehicleType,
        array $items,
        bool $active = true,
    ): self {
        $template = new self($id, '', null, $active, []);
        $template->apply($name, $vehicleType, $items, $active);

        return $template;
    }

    /**
     * Reconstituye una plantilla desde persistencia, sin grabar eventos.
     *
     * @param list<ChecklistTemplateItem> $items
     */
    public static function reconstitute(
        ChecklistTemplateId $id,
        string $name,
        ?string $vehicleType,
        bool $active,
        array $items,
    ): self {
        return new self($id, $name, $vehicleType, $active, array_values($items));
    }

    /**
     * Reemplaza el contenido de la plantilla (nombre, tipo, ítems, activa) y graba el evento.
     *
     * @param list<ChecklistTemplateItem> $items
     */
    public function redefine(string $name, ?string $vehicleType, array $items, bool $active): void
    {
        $this->apply($name, $vehicleType, $items, $active);
    }

    /**
     * Evalúa un checklist ejecutado contra esta plantilla.
     *
     * Las claves se comparan normalizadas (minúsculas, sin espacios en los extremos). Los ítems del
     * checklist que no están en la plantilla se ignoran para la criticidad.
     */
    public function evaluate(PreTripChecklist $checklist): ChecklistEvaluation
    {
        $submitted = [];
        foreach ($checklist->toArray() as $key => $checked) {
            $submitted[ChecklistTemplateItem::normalizeKey((string) $key)] = $checked;
        }

        $failed = [
            ChecklistItemCriticality::Critical->value => [],
            ChecklistItemCriticality::Major->value => [],
            ChecklistItemCriticality::Minor->value => [],
        ];
        $missingCritical = [];

        foreach ($this->items as $item) {
            if (!array_key_exists($item->key, $submitted)) {
                if ($item->criticality->blocksDispatch()) {
                    $missingCritical[] = $item->key;
                }

                continue;
            }
            if ($submitted[$item->key] === false) {
                $failed[$item->criticality->value][] = $item->key;
            }
        }

        return ChecklistEvaluation::of(
            $this->id->toString(),
            $failed[ChecklistItemCriticality::Critical->value],
            $failed[ChecklistItemCriticality::Major->value],
            $failed[ChecklistItemCriticality::Minor->value],
            $missingCritical,
        );
    }

    /**
     * Texto visible de un ítem por su clave (para armar motivos legibles), o la clave si no existe.
     */
    public function labelFor(string $key): string
    {
        foreach ($this->items as $item) {
            if ($item->key === $key) {
                return $item->label;
            }
        }

        return $key;
    }

    /**
     * @param list<ChecklistTemplateItem> $items
     */
    private function apply(string $name, ?string $vehicleType, array $items, bool $active): void
    {
        $name = trim($name);
        if ($name === '') {
            throw FleetException::because('La plantilla del checklist necesita un nombre.');
        }
        if ($items === []) {
            throw FleetException::because('La plantilla del checklist necesita al menos un ítem.');
        }

        $keys = [];
        foreach ($items as $item) {
            if (isset($keys[$item->key])) {
                throw FleetException::because("La clave {$item->key} está repetida en la plantilla del checklist.");
            }
            $keys[$item->key] = true;
        }

        $vehicleType = $vehicleType !== null ? strtolower(trim($vehicleType)) : null;

        $this->name = $name;
        $this->vehicleType = $vehicleType === '' ? null : $vehicleType;
        $this->items = array_values($items);
        $this->active = $active;

        $this->recordEvent(new ChecklistTemplateDefined(
            $this->id->toString(),
            $this->name,
            $this->vehicleType,
            $this->active,
            array_map(static fn (ChecklistTemplateItem $item): array => $item->toArray(), $this->items),
        ));
    }

    public function id(): ChecklistTemplateId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function vehicleType(): ?string
    {
        return $this->vehicleType;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return list<ChecklistTemplateItem>
     */
    public function items(): array
    {
        return $this->items;
    }
}
