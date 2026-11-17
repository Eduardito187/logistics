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

## Receta C — Agrupar / anidar el menú

- `getNavigationGroup(): string { return __('Operations'); }` en cada Resource agrupa en el menú.
- **`getNavigationParentItem()` SÍ existe y funciona** en esta versión de Filament v4 (CORRECCIÓN 2026-11-16:
  una nota vieja decía que no — era falsa; `OutboxMessageResource` e impresoras de red ya lo usan). Anida un
  Resource BAJO otro ítem del menú (p. ej. «Configuration») en vez de dejarlo suelto:
  ```php
  public static function getNavigationGroup(): string { return __('Administration'); }
  public static function getNavigationParentItem(): string { return __('Configuration'); } // cuelga de «Configuration»
  public static function getNavigationSort(): int { return 63; }
  ```
  Patrón de la **sección Configuración** (outbox, versiones de config, impresoras de red, plantillas de
  checklist): todas con grupo `Administration` + parent `Configuration`. Para mover una config nueva ahí,
  copiar esos 3 métodos. El parent se matchea por su etiqueta de navegación.
- `NavigationGroup::make(__('X'))->collapsed()` en `AdminPanelProvider` sigue sirviendo para ordenar/colapsar grupos.
- Instaladores (`filament:install`) reescriben `bootstrap/providers.php` → revisar y restaurar el orden.

## Receta D — Resource dependiente de SOLO LECTURA (se crea desde fuera del admin)

Cuando un registro dependiente lo genera otra fuente (la app del chofer, un comando, SAP) y el admin solo lo
CONSULTA — p. ej. el checklist pre-salida se REALIZA desde el camión (ConductPreTripChecklist), no en el admin:

1. Fuera del menú (`shouldRegisterNavigation(): false`) + enlazado desde el hub del padre (Receta A).
2. **Sin alta ni edición en el admin:**
   - `public static function canCreate(): bool { return false; }`
   - Quitar `'create'` de `getPages()` y **borrar** la página `Create…` (archivo) + su import.
   - Quitar el `CreateAction` del header de la página `List…`.
   - Borrar el `form()` muerto y `defaultChecklistRows()`/helpers que solo servían al create, + sus imports
     (si no, Pint marca `no_unused_imports`). `table()` deja solo `ViewAction` (o `recordActions([])`).
3. **Vista de detalle clara** (`infolist`): secciones legibles — QUIÉN lo hizo (chofer, vía relación, nunca
   UUID), EN QUÉ entidad, CUÁNDO, RESULTADO (badge), y el detalle (ítems con ✓/✗ como lista `->listWithLineBreaks()->bulleted()`).
4. **Ajustar el test de cobertura** que renderizaba el create borrado (`CoverageResourcesRenderTest`): quitar el
   `Livewire::test(Create…)` + su import, o el gate de cobertura rompe.

## Gotchas de Blade / Alpine / Livewire en el admin (vistos en producción de dev, 2026-11-11)

1. **`@js(...)` dentro de atributos de un componente `<x-...>` NO se compila.** Blade deja el texto literal
   y al clic Alpine tira `SyntaxError: Invalid or unexpected token` (livewire.js) → el botón "no hace nada".
   ```blade
   {{-- MAL --}}  <x-filament::button wire:click="mountAction('recordOdometer', { vehicle_id: @js($row['vehicle_id']) })">
   {{-- BIEN --}} <x-filament::button wire:click="mountAction('recordOdometer', {{ \Illuminate\Support\Js::from(['vehicle_id' => $row['vehicle_id']]) }})">
   ```
   Barrido: `php artisan view:cache` y grep de `@js(`, `@json(`, `@entangle(` en `storage/framework/views`.
2. **`->fillForm(fn ($arguments) => [...])` borra los `->default()` del schema.** Si precargás un campo por
   argumento (vehículo de la fila), incluí también los defaults (fechas = ahora, tipo por defecto) en ese
   array, o los requeridos quedan null y la validación falla.
3. **Acción que escribe + lista cacheada:** tras despachar el Command, `Cache::forget(<CLAVE>)` de la
   proyección que alimenta la lista (con la clave como constante pública), o la fila no cambia hasta el TTL.
4. **Panel en SPA (`->spa()`): todo `@script` con `setInterval`, mapa Leaflet o listeners debe liberarse al
   navegar.** Si no, `$wire.x()` del componente destruido devuelve `undefined` → `Uncaught TypeError:
   reading 'then'` cada tick y se acumulan intervalos/mapas. Usar `public/js/dismac-live.js`:
   ```js
   const live = window.DismacLive.session({ el, component: $wire.__instance });
   live.onDispose(() => map.remove());
   live.every(10000, () => live.call(() => $wire.positions(), (fresh) => { positions = fresh || []; render(); }));
   ```
   Cargarlo en `@assets` (`<script src="{{ asset('js/dismac-live.js') }}"></script>`). Componentes Alpine
   puros: `destroy()` con `clearInterval`, y no duplicar `x-init="init()"` (Alpine ya llama `init()`).
5. **Verificar sin navegador:** renderizar con `Livewire::actingAs($admin)->test(Page::class)`, extraer
   `effects.scripts` y pasarlo por `docker run --rm -i node:22-alpine node --check` envuelto como lo evalúa
   Livewire (`async function(__self, scope){ with(scope){ (async()=>{ … })() } }`). Igual pedirle al dueño
   el clic real y la consola limpia.

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
