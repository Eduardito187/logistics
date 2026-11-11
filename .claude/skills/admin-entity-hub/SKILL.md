---
name: admin-entity-hub
description: >-
  Consolidar el admin Filament de CRUD plano a CRM entidad-céntrico: convertir una entidad en un "Hub
  360" (cockpit de detalle) y "tucar" sus recursos dependientes fuera del menú hacia la sección «Gestión»
  de ese hub, filtrados a la entidad, sin huérfanos y sin UUIDs visibles. Usar al pedir "menos CRUD / más
  CRM", unificar ítems del menú, crear un hub de vehículo/chofer/envío/pedido/cliente/seller/carrier, o
  mover un Resource suelto a la ficha de su entidad padre.
---

# Admin entity hub — CRUD → CRM (Dismac logistics / Filament v4)

Meta: el menú lista **entidades y cockpits**, no filas CRUD. Cada registro que DEPENDE de una entidad
(mantenimiento→vehículo, liquidación→seller, escaneo→envío) vive en la ficha 360 de esa entidad, no como
ítem suelto. El dueño lo exige explícitamente ("quiero un CRM, no CRUD").

## Reglas duras (no negociar)

1. **Nunca huérfanos.** Un recurso que se saca del menú DEBE quedar enlazado desde algún hogar alcanzable
   (hub 360 o directorio). Antes de demotar, identificar su entidad padre.
2. **Nunca UUIDs visibles.** Resolver cada FK a su valor legible (placa, nombre, SKU, referencia). Ver
   memoria [[no-uuid-in-admin]].
3. **Texto visible en inglés + clave en `lang/es.json`** (regla 5 del proyecto). EXCEPCIÓN: los blades
   cockpit con la capa `.distrack-cockpit` van en español directo.
4. **DoD**: pest verde + PHPStan nivel 8 limpio + Pint PASS + verificación real en navegador.

## Receta A — Demotar un Resource al hub de su padre

### 1. Sacarlo del menú (con comentario que diga dónde vive ahora)
```php
// Fuera del menú: depende del VEHÍCULO; su hogar es la ficha 360 del vehículo (sección «Gestión»),
// que abre su lista filtrada por ?vehicle=. No es un ítem CRUD suelto.
public static function shouldRegisterNavigation(): bool
{
    return false;
}
```

### 2. Filtrar la lista por la entidad (el deep-link de Filament NO hidrata solo)
**GOTCHA verificado:** `?tableFilters[<f>][value]=X` **NO** hidrata el filtro desde la URL en esta versión
de Filament. Validaciones de "filteredRows=4" fueron coincidencia (4=total). Patrón que SÍ funciona:
```php
use App\Filament\Support\RequestScopedQuery;

public static function table(Table $table): Table
{
    return $table
        ->modifyQueryUsing(RequestScopedQuery::by('vehicle', 'vehicle_id'))   // un solo padre
        // ->modifyQueryUsing(RequestScopedQuery::byAny(['vehicle'=>'vehicle_id','driver'=>'driver_id'])) // varios
        ->columns([ /* valores legibles vía relaciones Eloquent, nunca el UUID */ ]);
}
```
Para FK anidada (escaneo→entrega→envío) usar `whereHas` dentro de un `modifyQueryUsing` propio.
Helpers disponibles: `RequestScopedQuery::by / byAny / touching / ownedBy`.

### 3. Enlazar desde el hub padre (`managementLinks` en el ViewRecord del padre)
```php
/** @return list<array{label: string, url: string}> */
public function managementLinks(): array
{
    $id = (string) $this->getRecord()->getKey();
    $out = [];
    try {
        $out[] = ['label' => 'Mantenimiento',
                  'url' => MaintenanceOrderResource::getUrl('index').'?vehicle='.rawurlencode($id)];
    } catch (Throwable) { /* recurso no disponible: se omite (tolerante) */ }
    return $out;
}
```
- Una **Page** (no Resource) se enlaza con `Page::getUrl()` (sin `'index'`), leyendo el parámetro en su
  `mount()` (`request()->query('driver')`).
- Verificar el filtrado **contando filtradas vs total con un subconjunto conocido** (p. ej. 1 de 4), nunca
  un número que casualmente coincida con el total.

## Receta B — Convertir un ViewRecord en Hub 360 (cockpit)

- `protected string $view = 'filament.<ctx>.<x>-hub';` (blade propio con `.distrack-cockpit`, no el infolist).
- `#[Computed] public function hub(): array { return app(<X>Hub::class)->overview((string)$this->getRecord()->getKey()); }`
- El read-service vive en `app/Support/Cockpit/<X>Hub.php`: agrega datos cross-context con `DB::table()` +
  joins, cachea la **forma primitiva** (array) con TTL corto (regla 7: nunca el objeto serializado),
  tolerante (si falla la fuente, degrada a vacío/0, no rompe la vista).
- `recordUrl` en el Resource para que la tabla abra el hub.
- Notas CRM: `use App\Filament\Concerns\HasEntityNotes;` + `noteSubjectType()` + `<x-cockpit.notes>`.

## Receta C — Agrupar el menú

- **`getNavigationParentItem()` NO existe** en esta versión de Filament (verificado). NO se puede anidar
  ítems; usar **grupos de navegación separados** en `AdminPanelProvider`:
  `NavigationGroup::make(__('Integrations'))->collapsed()`.
- `getNavigationGroup(): string { return __('Operations'); }` en cada Resource.
- Instaladores (`filament:install`) reescriben `bootstrap/providers.php` → revisar y restaurar el orden.

## Qué NO tucar (falsos positivos — dejar visible con justificación)

- Una pantalla sin **entidad-padre limpia** (su FK no referencia una entidad del admin). Ej.: "Stock
  disponible" (`inventory_lots.warehouse_id` NO existe en `network_nodes` → verificado por tinker). Tucarlo
  rompería el filtro o lo dejaría huérfano. Mantener como pantalla operativa legítima.
- Superficies de **compliance/reference** transversales (documentos por vencer de toda la flota, precios de
  combustible): son listas globales con valor propio, no leaves CRUD por-entidad.
- Verificar SIEMPRE la relación real antes de demotar: `php artisan tinker --execute="..."` o revisar la
  migración de la tabla.

Relacionado: memorias [[admin-crm-phases]], [[no-uuid-in-admin]], [[filament-v4-component-gotchas]],
[[cockpit-control-tower]], [[routing-geo-rule]].
