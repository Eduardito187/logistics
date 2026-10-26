---
name: admin-ops-first-redesign
description: "Admin Filament rediseñado operaciones-primero: IA de 5 grupos, Control Tower como landing, cockpits por frente, gating de 2 roles, componentes dropdown propios"
metadata:
  node_type: memory
  type: project
  originSessionId: 95be9a11-8872-4d3d-b8b2-ef0548264b66
  modified: 2026-10-15T06:42:20.433Z
---

Refactor del 2026-10-14 (rama `feature/plataforma-logistica-completa`): el dueño veía el admin como
"un CRUD básico, nada transaccional". Se rediseñó la CAPA DE PRESENTACIÓN (Filament) a
**operaciones-primero**, sin tocar dominio. **Por qué:** un sistema real muestra "qué requiere acción
AHORA" y actúa por comandos, no un explorador de tablas. **Cómo aplicarlo / mantenerlo:**

**1. Arquitectura de información: 16 grupos → 5.** En `AdminPanelProvider::navigationGroups([...])` quedan
solo `Operations, Fleet, Network, Commerce, Administration` (por modelo mental del operador, no un grupo
por bounded context). Cada Resource/Page declara su grupo con getters `getNavigationGroup(): string`
(devuelve `__('Operations'|'Fleet'|'Network'|'Commerce'|'Administration')`) y `getNavigationSort(): int`
(usar `: int`, NO `?int`: PHPStan 8 marca `return.unusedType`). Dentro de cada grupo, los cockpits y
páginas de acción van con sort BAJO (arriba) y los catálogos CRUD con sort alto (abajo).

**2. Landing = Control Tower, no el Dashboard genérico.** `App\Filament\Pages\ControlTower extends
Filament\Pages\Dashboard` con `protected static string $routePath = '/'` → es la home. Se auto-descubre
por `discoverPages(app_path('Filament/Pages'))`; se QUITÓ `Filament\Pages\Dashboard::class` de
`->pages([...])` (ya no se registra el dashboard por defecto). `getWidgets()` ordena los widgets con
narrativa **excepciones → salud → KPIs**. Widgets en `app/Filament/Widgets/*` + concern
`ReadsControlTowerData` (lectura tolerante try/catch→0 + `canView()` Admin/Supervisor). Gotcha: si una
página (ControlTower) referenciada por el sidebar NO está registrada, TODO el panel da 500
("Route [filament.admin.pages.control-tower] not defined"); registrar la home antes de verificar renders.

**3. Cockpits por frente (Pages de acción, reutilizan Commands existentes vía `CommandBus`):**
- Operations: `LastMileBoard` (execution, slug `last-mile-board`) — cola del día por estado, POD/fallida inline.
- Fleet: `LiveFleetMap` (telemetry) — mapa + panel de excepciones (alertas sin reconocer, inactivos/ralentí) + Acknowledge inline; `FleetCostDashboard` (fleetops) — mantenimiento vencido, docs por vencer, odómetro atrasado, gasto del día + TCO/costo-km al fondo; `DriverHistory`.
- Patrón de acción inline: replicar `DeliveryResource::dispatch()` (helper que resuelve `CommandBus` y notifica). NO escribir Eloquent directo para operaciones de negocio.
Las Pages de package se registran a mano en `AdminPanelProvider->pages([...])` (no se auto-descubren).

**4. Gating: en el admin solo hay 2 roles.** `User::canAccessPanel()` ya bloquea a todos salvo Admin y
Supervisor (los de campo usan la app Flutter). Regla: el grupo **Administration** es Admin-only (trait
`Logistics\Access\Presentation\Filament\Concerns\AdminOnly` en TODOS sus resources: access + integrations
+ SAP + notifications config + outbox + settings); el resto de grupos lo ven ambos. NO relajar los 4
FleetOps que ya eran AdminOnly (Departure/OdometerReading/Expense/VehicleProfile). Ver [[filament-admin-hardening]].

**5. Selects: Filament ya cumple la regla 5.** Los 95 `Select::make` del admin YA llevan `->native(false)`
(dropdown propio de Filament basado en div, no `<select>` nativo) + searchable/preload donde referencian
entidad. GOTCHA de verificación: `grep "->native(false)"` da falso 0 porque el patrón empieza con `-`;
usar `grep -- "->native(false)"`. Los únicos `<select>` nativos reales estaban en blades/SPA hechos a
mano → se reemplazaron por componentes dropdown propios (ver [[admin-ui-maps]]).

**6. Mapas grises arreglados:** helper de tiles resiliente con fallback a OSM público. Ver [[admin-ui-maps]].

Verificación: sweep de render autenticado como admin@dismac.test por el HTTP kernel sobre home +
`getPages()` + `getResources()::getUrl('index')` → **60/60 HTTP 200**; **PHPStan 8 global = 0** y
**Pint PASS (2532 files)** en TODO el repo. (El script temporal del sweep va DENTRO del repo —el container
no ve el scratchpad del host— y se BORRA al terminar: un `_tmp_*.php` olvidado ya se coló commiteado.)

GOTCHA que costó tiempo: un primer `phpstan analyse` global reportó "8 errores" en fleetops/tracking que
**NO existían** — eran FANTASMAS del result-cache (estado viejo tras `dump-autoload` + edición de sesiones
concurrentes). Con `phpstan clear-result-cache` el conteo real fue **0**. Además el `phpstan.neon` tiene
`treatPhpDocTypesAsCertain: false`, así que las guardas `!== null` sobre retornos `?T` por PHPDoc son
legítimas (no muertas, no bug). El único error real era `planning/RouteSequencer.php` (`array_values()`
redundante sobre un `non-empty-list`), corregido. Para los stragglers de Pint de un contexto que otra
sesión escribía en vivo (planning), un `pint packages/logistics/planning` en bloque — NO perseguirlos uno
por uno. Ver [[multisession-shared-tree]], [[filament-v4-component-gotchas]].

Relacionado: [[filament-admin-hardening]], [[fleetops-context]], [[telemetry-context]], [[filament-v4-component-gotchas]].
