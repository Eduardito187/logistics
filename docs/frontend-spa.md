# App web operativa (Vue + Inertia) — convenciones

La operación interactiva vive como SPA en **/app** (Inertia + Vue 3 + Vite + Ziggy + Tailwind v4). El
CRUD/config sigue en **Filament (/admin)** — NO se toca. Tema oscuro, rojo de marca `#E2231A`, textos en
español. Barra de progreso superior ya configurada.

## Estructura (dónde va cada cosa)

- **Páginas Inertia**: `resources/js/Pages/<Modulo>/<Pagina>.vue` (p.ej. `Pages/Deliveries/Index.vue`).
- **Componentes compartidos (REUSAR, no recrear)**: `resources/js/Components/UI/*` y `Components/Map/*`:
  - `@/Components/UI/PageHeader.vue` (title, subtitle, slot `actions`)
  - `@/Components/UI/AppCard.vue` (title opcional, slot cuerpo)
  - `@/Components/UI/StatusBadge.vue` (`label`, `variant`: gray|green|red|amber|blue)
  - `@/Components/UI/DataTable.vue` (`columns`[{key,label,align}], `rows`, `clickable`, `@row-click`, slots `cell-<key>`)
  - `@/Components/Map/RouteMap.vue` (`stops`,`planned`,`actual`; expone `focusStop(seq)` por ref)
- **Componentes propios del módulo**: `resources/js/Components/<Modulo>/*.vue`.
- **Layout**: `AppLayout` se aplica solo (default en `app.js`). NO lo importes ni lo toques. La navegación
  ya tiene el ítem de cada módulo; aparece cuando su ruta existe.
- **Controllers**: `app/Http/Controllers/App/<Modulo>/<X>Controller.php` (namespace `App\Http\Controllers\App\<Modulo>`).
- **Rutas**: cada módulo crea SU PROPIO `routes/app/<modulo>.php` (se auto-incluye; ya está dentro del
  grupo `auth` + prefix `app` + name `app.`). Define ahí tus rutas: `Route::get('deliveries', ...)->name('deliveries.index')`.

## Patrón de referencia (ya existe, COPIAR)

Módulo Operaciones/Rutas (el piloto):
- `routes/app/operations.php`
- `app/Http/Controllers/App/Operations/RouteController.php` (lee el read-side Eloquent, solo lectura)
- `resources/js/Pages/Operations/Routes/Index.vue` (listado con DataTable, clic → detalle)
- `resources/js/Pages/Operations/Routes/Show.vue` (detalle con RouteMap + tarjetas clickeables que
  centran el mapa vía `mapRef.focusStop(seq)`)

El controlador devuelve `Inertia::render('<Modulo>/<Pagina>', [ ...props ])`. Navegación con `<Link :href="route('app.<modulo>.<x>')">` o `router.visit(route(...))`. `route()` es Ziggy (global).

## Datos — LECTURA

- Lee el read-side: los `*Record` Eloquent del contexto (igual que hacen los Filament Resources — míralos
  para conocer campos/relaciones reales) o, mejor, el QueryBus si hay query. Resuelve los valores legibles
  (no UUIDs crudos): placa, external_id, nombre de cliente, etc. Evita N+1 con `->with([...])`.
- Reusa la lógica de coordenadas/mapa del `RouteController` del piloto para pantallas con mapa.

## Datos — ESCRITURA (acciones del operador)

Las escrituras de negocio van SIEMPRE por el dominio (Presentation → Application), nunca escribiendo el
Record a mano. Patrón ya usado en planning y deliveries:
- Un controlador `App\Http\Controllers\App\<Modulo>\<X>WriteController`: valida el input, resuelve el id,
  `app(\Logistics\Shared\Application\Command\CommandBus::class)->dispatch(new XxxCommand(...))`, captura la
  excepción de dominio y hace `back()->with('success'|'error', '...')`.
- Rutas de escritura en archivo PROPIO `routes/app/<modulo>-writes.php` (POST/PUT; no mezclar con el de lectura).
- Vue: `useForm`/`router.post|put` de Inertia; el **flash global de AppLayout** muestra success/error (no hace
  falta renderizarlo por página). Gatea la UI por estado del dominio (ej. acciones solo si la entrega está
  LOADED/IN_TRANSIT). Si un Command que necesitas NO existe, NO inventes la escritura: repórtalo (ej. "mover
  parada entre camiones" requirió el Command nuevo `ReassignShipmentToVehicle` en planning).

## Reglas duras (del dueño)

- **NO ejecutar tests** (pest/phpunit). Escribirlos sí; correrlos NO.
- **NO operaciones destructivas de DB** (nada de migrate:fresh/wipe/truncate).
- **NO correr `npm`/`vite`/`npm run build`** (varios builds concurrentes corrompen node_modules: ENOTEMPTY).
  El build final lo hace el orquestador una sola vez.
- **NO tocar archivos compartidos**: `vite.config.js`, `routes/web.php`, `resources/js/app.js`,
  `bootstrap/app.php`, `resources/js/Layouts/*`, `resources/js/Components/UI/*`, `Components/Map/*`,
  `app/Http/Middleware/HandleInertiaRequests.php`. Si te falta un componente compartido o un ítem de nav,
  NO lo edites: constrúyelo como componente propio del módulo o repórtalo en tu entrega.
- Verificación permitida (no-test): `php -l` sobre tus PHP, PHPStan nivel 8 sobre tus archivos
  (`docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G <tus archivos>`), Pint sobre
  tus archivos. NO corras la suite ni el build.
- Comentarios en español; textos visibles en español.
