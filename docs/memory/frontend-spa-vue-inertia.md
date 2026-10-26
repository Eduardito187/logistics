---
name: frontend-spa-vue-inertia
description: "App web operativa Vue 3 + Inertia en /app (híbrido con Filament) — stack, estructura, gotchas de build"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-14T00:11:20.130Z
---

Decisión 2026-10-13 (ver `docs/adr/009-app-web-vue-inertia.md`, enmienda ADR 005): el frontend NO se
migra "blade a Vue" (el admin es Filament: 36 Resources, no blades). **Híbrido**: Filament (/admin) se
queda para CRUD/config; nueva **app web Vue 3 + Inertia** en **/app** para la operación interactiva (torre
de control, planificación, rutas, entregas, pedidos, seguimiento). SPA con barra de progreso, tema oscuro,
rojo #E2231A, textos en español, mapas Leaflet+OSM. Elegido **Vue** (no React, que decía ADR 005).

**Stack**: inertiajs/inertia-laravel + tightenco/ziggy (composer, en container) · vue@3 + @inertiajs/vue3 +
@vitejs/plugin-vue + ziggy-js (npm, en HOST) · Vite + Tailwind v4.

**Estructura y contrato**: `docs/frontend-spa.md` (léelo antes de tocar el SPA). Resumen:
- Fundación (NO tocar, compartida): `resources/js/app.js`, `Layouts/AppLayout.vue`, `Components/UI/*`
  (PageHeader, AppCard, StatusBadge, DataTable), `Components/Map/RouteMap.vue` (expone `focusStop(seq)`),
  `resources/views/app.blade.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `vite.config.js`
  (alias `@`→resources/js), `routes/web.php` (grupo auth+prefix 'app'+name 'app.' que auto-incluye
  `routes/app/*.php`), `bootstrap/app.php` (middleware Inertia + redirectGuestsTo login Filament).
- Por módulo: `routes/app/<modulo>.php` (propio), `app/Http/Controllers/App/<Modulo>/*`, `Pages/<Modulo>/*.vue`,
  `Components/<Modulo>/*`. Piloto de referencia: Operaciones/Rutas (Index/Show + mapa + tarjetas clickeables).
- Solo lectura: lee read-side (*Record como hacen los Filament Resources, o QueryBus); valores legibles, no UUIDs.
- La nav de AppLayout ya lista los módulos; un ítem aparece solo cuando su ruta Ziggy existe.

**Gotchas de build (verificados)**:
- `npm`/`vite` corren en el HOST (el container `app` NO tiene Node). Composer sí en el container.
- NUNCA dos `npm install` a la vez → `ENOTEMPTY: rename node_modules/...` corrompe node_modules. Síntoma:
  carpetas de paquete vacías y `UNRESOLVED_IMPORT @vitejs/plugin-vue`. Fix: matar todo npm, borrar
  `node_modules/.<pkg>-*` temporales, reinstalar UNA vez en foreground.
- `package-lock.json` quedó como root (otra corrida); si npm da EACCES al lock, borrarlo (el dir es del
  usuario) o usar `--no-package-lock`. Al delegar a agentes: prohibirles correr npm/vite/build (lo hace el
  orquestador una sola vez) para evitar builds concurrentes.
- Build OK confirmado: `npm run build` → 578 módulos, compila Vue+Inertia+componentes+piloto y el tema Filament.

**Estado 2026-10-13 (construido, build verde, PHPStan 8 limpio en app/):** módulos del SPA en /app:
Panel, Operaciones/Rutas, Torre de control, Planificación (con **tablero DnD** y **tablero del día** que
arrastra pedidos ENTRE camiones), Entregas, Pedidos, Seguimiento, **Personal** (perfiles de chofer/ayudante
con foto/documentos/referencias + desempeño de GetDriverHistory). **Login propio en Vue** (`routes/app-guest/`,
LoginController, redirectGuestsTo→app.login) con logout y **flash global** en AppLayout. ~40 rutas `app.*`.

**Patrón de ESCRITURA desde el SPA** (ya en planning y deliveries): un controlador Inertia
(`App\Http\Controllers\App\<Modulo>\<X>WriteController`) valida el input, despacha el Command del dominio por
`app(CommandBus::class)->dispatch(new XxxCommand(...))`, captura la excepción de dominio y hace
`back()->with('success'|'error', ...)`. Las rutas de escritura van en un archivo propio
`routes/app/<modulo>-writes.php`. Vue usa `useForm`/`router.post` y el flash global muestra el resultado.
Escrituras habilitadas: planning (ReorderStops, PublishPlan, GenerateDailyPlan, **ReassignShipmentToVehicle**
nuevo = mover entre camiones), deliveries (RecordProofOfDelivery, RecordFailedDelivery, ReportVehicleIncident).

**Orquestación de la migración (patrón que funcionó)**: fundación + librería de componentes + 1 módulo piloto
primero (el orquestador, dueño de los archivos compartidos), luego un **contrato** (docs/frontend-spa.md,
docs/fleet-expansion.md) y agentes en PARALELO por módulo, cada uno con su propio archivo de rutas/controladores/
páginas, SIN tocar compartidos ni correr build/tests; el orquestador hace el build único, mergea i18n
central (lang/es.json) y verifica PHPStan global.

Relacionado: [[admin-ui-maps]] (Filament/tema/mapas), [[filament-v4-component-gotchas]], [[no-ejecutar-tests]],
[[no-destructive-db-ops]], [[fleet-personnel-expansion]].
