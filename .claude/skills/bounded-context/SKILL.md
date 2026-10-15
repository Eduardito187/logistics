---
name: bounded-context
description: >-
  Construir o extender un bounded context de la plataforma logística Dismac con la arquitectura
  objetivo del proyecto: DDD + CQRS + Clean Architecture, UUID v7 y outbox, orientada a microservicios
  (cada contexto extraíble a servicio) para procesamiento veloz de millones de registros y procesos
  transaccionales en simultáneo. Usar al crear un package nuevo en packages/logistics/<context> o al
  darle dominio a uno existente (network, coverage, fleet, shipments, planning, execution, tracking,
  notifications, capacity, promise, sap-connector, carriers).
---

# Bounded context — DDD + CQRS + Clean Architecture (Dismac logistics)

Arquitectura objetivo (memoria del proyecto, ver `CLAUDE.md` y `docs/adr/008-ddd-cqrs-uuidv7-outbox.md`):
respuestas rápidas sobre millones de registros, procesos transaccionales simultáneos, errores aislados
por capa, y cada contexto **extraíble a microservicio** sin reescribir.

## Reglas duras (no negociar)

1. **Clean Architecture**: dependencias `Presentation → Application → Domain ← Infrastructure`.
   - `Domain` es PHP puro: PROHIBIDO `use Illuminate\...`, Eloquent o helpers globales. Verificable:
     `grep -rl Illuminate packages/logistics/<ctx>/src/Domain` debe salir vacío.
   - `Presentation` nunca llama a `Infrastructure` ni a `Domain` directo: habla con los buses CQRS.
2. **DDD**: un agregado por raíz (`extends Logistics\Shared\Domain\Aggregate\AggregateRoot`), value
   objects `final readonly` con constructor privado + named constructors, eventos de dominio que
   extienden `Logistics\Shared\Domain\Event\BaseDomainEvent`, repositorios como interfaces en
   `Domain/Contract`.
3. **UUID v7**: todo id es un id tipado que extiende `Logistics\Shared\Domain\ValueObject\AbstractId`.
   Nunca enteros autoincrementales como identidad de dominio.
4. **CQRS**: escritura = `Command` + un `CommandHandler` (void); lectura = `Query` + `QueryHandler`
   que devuelve ReadModels (`final readonly`, `toArray()` primitivo). Registrar cada par
   mensaje→handler en el `HandlerRegistry` desde el `boot()` del ServiceProvider.
5. **Outbox para I/O externo**: los handlers de escritura hacen `releaseEvents()` del agregado y los
   mandan al `logistics/outbox` (`RecordDomainEvents`) en la MISMA transacción. Nada de HTTP síncrono.
   El despacho real (SAP/FCM/email/SMS) es un `MessageDispatcher` que corre en el relay, idempotente.
6. **Cache**: si se cachea, se cachea la forma primitiva (array del ReadModel), nunca el objeto de dominio.
7. Texto visible de admin/consola **en inglés**; comentarios y docs **en español**.

## Estructura de carpetas

```
packages/logistics/<context>/
├── composer.json            # name logistics/<context>; PSR-4 "Logistics\<Context>\": "src/";
│                            #   require logistics/shared ^0.1; provider en extra.laravel.providers
├── README.md                # responsabilidad, no-responsabilidad, estado
├── database/migrations/     # uuid pk string; jsonb; PostGIS por SQL donde haya geometría
├── src/
│   ├── Domain/              # ValueObject/, Model/ (agregados+entidades), Event/, Contract/, Service/, Exception/
│   ├── Application/         # Command/<UseCase>/{Command,Handler}, Query/<Q>/{Query,Handler}, ReadModel/
│   ├── Infrastructure/      # Persistence/Eloquent/{Record,Mapper,Repository}, Providers/<Ctx>ServiceProvider
│   └── Presentation/        # Console/, Http/ (controllers), Filament/
└── tests/{Unit,Feature}/
```

## Núcleo compartido disponible (`logistics/shared`)

- `Domain\ValueObject\Uuid` (v7: `generate()`, `fromString()`, `->value`, `->equals()`, `timestampMillis()`).
- `Domain\ValueObject\AbstractId` (base de ids tipados: `generate()/fromString()/fromUuid()/toString()/equals()`).
- `Domain\Aggregate\AggregateRoot` (`recordEvent()` protected, `releaseEvents(): list<DomainEvent>`).
- `Domain\Event\{DomainEvent,BaseDomainEvent}` (ctor `(string $aggregateId, ?Uuid, ?DateTimeImmutable)`;
  definís `eventName()` namespaced `"<ctx>.<hecho>"` y `payload(): array<string,mixed>`).
- `Domain\Clock\Clock`, `Domain\Exception\DomainException`.
- `Application\Command\{Command,CommandHandler,CommandBus}`, `Application\Query\{Query,QueryHandler,QueryBus}`,
  `Application\HandlerRegistry` (`register(string $msgClass, string $handlerClass)`).

## Pasos

1. Crear la estructura y el `composer.json` (copiar `packages/logistics/labels/composer.json` como molde).
2. Modelar el `Domain` (ids tipados, VOs, agregado con named constructors que graban eventos, contratos de repo).
3. Escribir `Application`: un command+handler por caso de uso de escritura; queries+handlers con ReadModels.
4. `Infrastructure`: modelo Eloquent (`$incrementing=false`, `$keyType='string'`), mapper dominio↔fila,
   repo Eloquent, migración (uuid pk; PostGIS con `ST_*` por SQL si hay geometría), y el ServiceProvider
   que bindea repos, registra handlers en `HandlerRegistry` y hace `loadMigrationsFrom`.
5. **Registrar el package en DOS lugares** (regla 3 de `CLAUDE.md`): `bootstrap/providers.php` y
   `extra.laravel.dont-discover` del `composer.json` raíz. El núcleo `SharedServiceProvider` va primero.
6. Tests: `Unit` (dominio, sin Laravel) + `Feature` (stack contra PostgreSQL real; PostGIS si aplica).

## Definición de Terminado

**REGLA FUERTE DEL DUEÑO — NO EJECUTAR TESTS** ([[no-ejecutar-tests]]): escribir y mantener tests SÍ,
pero NUNCA correr `pest`/`phpunit`/`php artisan test`/`make test`. Esto PRIMA sobre el "tests verdes"
de `CLAUDE.md`. Verificar con: `phpstan analyse` nivel 8 limpio + `./vendor/bin/pint` limpio + lectura +
checks puntuales no-test (autoload `php -r 'class_exists(...)'`, `php artisan route:list`, `view:clear`,
una query/endpoint concreto) + `openapi/v1.yaml` actualizado si se tocó un endpoint. "100%/check" es
ambiguo: ante la duda, preguntar antes de correr la suite, no asumir que piden la DoD completa con pest.

**NO operaciones destructivas de DB** ([[no-destructive-db-ops]]): jamás `migrate:fresh`/`refresh`/`db:wipe`/
`TRUNCATE`/`DROP` sobre `logistics` (regenera los UUID v7 de demo → todos los deep-links del admin dan 404,
irreversible). Verificar seeders con SELECT/JOINs sobre la base actual; backup (`pg_dump`) antes de algo
riesgoso; seeders idempotentes y UUID-estables.

## Gotchas de integración (verificados 2026-10-13, costaron errores reales)

Al generar presentación/datos de un package, respetar esto o el admin/seed rompe:

1. **Autoload de `database/seeders` y `database/factories` de un package**: el PSR-4 debe estar en
   `autoload` (NO `autoload-dev`) y mapear el **namespace completo a la carpeta en minúscula**
   (PSR-4 es case-sensitive): `"Logistics\\Fleet\\Database\\Seeders\\": "packages/logistics/fleet/database/seeders/"`.
   Lo más robusto es declararlo en el `composer.json` **raíz**. Si se edita el `composer.json` de un
   package path, `composer dump-autoload` NO basta: hay que `composer update "logistics/*"` para que
   composer re-lea el composer.json del package path y fusione su autoload.
2. **Factories**: nunca usar `static fn` / `static function` en states/definition/afterCreating; Laravel
   hace `$state->bindTo($this)` y falla con "Cannot bind an instance to a static closure". Usar closures
   normales (`fn`/`function`).
3. **Seeders idempotentes**: `firstOrCreate`/`updateOrCreate`, nunca `create` ciego sobre columnas
   únicas (el seeder se corre varias veces). Quitar el `User::factory()->create(['test@example.com'])`
   por defecto de Laravel.
4. **Filament — registro de Resources de packages**: `AdminPanelProvider` solo descubre
   `app/Filament/Resources` por defecto. Añadir un `->discoverResources(in: base_path('packages/logistics/<ctx>/src/Presentation/Filament'), for: 'Logistics\<Ctx>\Presentation\Filament')`
   por cada package, o si no, los módulos NO aparecen en `/admin`.
5. **Filament — Resources completos**: cada `Resource` debe traer TODAS las Pages que referencia en
   `getPages()` (List/Create/Edit/View) en `<ResourceDir>/<Resource>/Pages/`. Una Page custom suelta
   (que no es Resource) a veces no registra su ruta en v4 → "Route [...] not defined"; preferir un
   `Resource` real antes que una `Page` custom para listar datos.
6. **Verificación mínima tras generar** (aunque no se corran pest/phpstan): `composer dump-autoload` +
   `php artisan migrate --seed` + `php artisan route:list` dejan ver al instante class-not-found,
   PSR-4, migraciones y rutas. Para ver errores de render del admin sin navegar: iterar
   `Filament::getResources()` llamando `::getUrl()` y `::getEloquentQuery()->limit(1)->get()`.
7. **Ramas**: `git fetch --all` ANTES de juzgar qué tiene cada rama; los refs locales llegan viejos.
   El trabajo real vive en `production` (no en ramas de scaffold).
8. **i18n (admin en español)**: el código queda en INGLÉS; los textos visibles pasan por `__('English')`
   y se traducen con `lang/es.json` (diccionario fuente→es, estilo Magento) + `APP_LOCALE=es`. Filament
   ya trae su propio `es`. Al convertir propiedades estáticas de Filament (`$navigationGroup`,
   `$navigationLabel`, `$modelLabel`, `$pluralModelLabel`) a getters para poder llamar `__()`, el
   método DEBE seguir siendo **`static`** (p.ej. `public static function getPluralModelLabel(): string`)
   — igual en `RelationManager::getModelLabel()` (es static en el padre). Hacerlo de instancia revienta
   el boot con "Cannot make static method non static".
9. **Datos legibles, no UUIDs** en Filament: `$recordTitleAttribute`/`getRecordTitle()` con campo humano;
   columnas de FK mostradas por relación (`TextColumn::make('vehicle.plate')`) con `getEloquentQuery()->with([...])`
   para evitar N+1; UUIDs como columnas `toggleable()` ocultas. OJO: no asumir que una columna `*_id`
   es UUID — p.ej. `scan_events.unit_id` guarda un código escaneado (string), no una FK; no armar
   `belongsTo` contra una tabla UUID sobre esa columna.
10. **Flujos transaccionales, no CRUD**: las acciones del admin ejecutan los Command del dominio
    (`app(CommandBus::class)->dispatch(new XxxCommand(...))`), nunca escriben el Record directo para
    operaciones de negocio (respeta invariantes + outbox).

## Gotchas PHPStan nivel 8 + Filament v4 (verificados 2026-10-13; limpiaron 201 errores repo-wide a 0)

11. **Patrones de arreglo REAL de PHPStan 8** (sin `mixed`, sin `@phpstan-ignore`, sin bajar tipos):
    - `return.unusedType` en getters de Filament (`getNavigationGroup(): ?string`, `getNavigationIcon(): string|BackedEnum|null`
      que SIEMPRE devuelven string): **angostar el retorno al tipo real** (`: string`). Covariante y legal.
    - `nullsafe.neverNull`: `$record->rel?->prop` donde la relación nunca es null → `$record->rel->prop`.
    - `missingType.generics` en `newFactory(): Factory`: devolver el **tipo concreto** del factory
      (`newFactory(): FooRecordFactory`), que fija el genérico vía `@extends Factory<FooRecord>`.
    - `method.childReturnType` en `Factory::definition()`: **quitar** el phpdoc `@return array<string, mixed>`
      (el padre pide `array<model property, mixed>`, no escribible en phpdoc); el tipo nativo `array` sí es compatible.
    - `return.type` en `getPages()`: el phpdoc correcto es `@return array<string, \Filament\Resources\Pages\PageRegistration>`.
    - `notIdentical.alwaysTrue`/`identical.alwaysFalse`: comparaciones muertas (`isset($x) && $x !== null`) — quitar la parte redundante; a veces es un BUG real (variable equivocada).
    - `argument.type` en builders de Filament (`whereHas`/`modifyQueryUsing`): convertir el arrow-fn a closure con `/** @var Builder<FooRecord> $query */`.
    - **Nunca sombrear métodos del framework**: `Request::date()`, `Factory::store()` colisionan por firma → renombrar (`calendarDate()`, `asStore()`).
12. **Factories en `src/Infrastructure/Persistence/Eloquent/Factory/`** (NO en `database/factories/`): el
    ignore de `method.childReturnType` del `phpstan.neon` solo cubre esa ruta `src/.../Factory/*`. Mapear
    su PSR-4 en el composer del package y quitar cualquier mapping `Database\Factories\` muerto.
13. **Clase base de componentes Filament v4 = `Filament\Schemas\Components\Component`** (NO `Filament\Forms\Components\Component`,
    que no existe en v4). Un `Resource` necesita `@extends Resource<FooRecord>` para que `parent::getEloquentQuery()`
    tipe `Builder<FooRecord>` en vez de `Builder<Model>`.
14. **Seeders: idempotentes Y UUID-estables.** Guardia `if (FooRecord::query()->where('<clave estable>', ...)->exists()) return;`
    (o skip por ítem con `findBy...`). NUNCA regenerar el UUID en re-run (romper esto + un `migrate:fresh`
    regenera todos los IDs de demo y **todo deep-link del admin da 404** — ver [[no-destructive-db-ops]]).
    Verificación de idempotencia: `db:seed` dos veces seguidas, todo DONE, EXIT 0 (sin `migrate:fresh`).

## Gotchas UI/mapas/tema (verificados 2026-10-13)

15. **Conflicto de nombre `Schema`**: Filament v4 usa `Filament\Schemas\Schema` (infolist/form). Si en el
    mismo archivo necesitas el facade de BD, impórtalo aliasado: `use Illuminate\Support\Facades\Schema as DbSchema;`
    y usa `DbSchema::hasTable/hasColumn`. Dos `use ...Schema;` = fatal "name already in use" (rompe TODO el boot).
16. **Props de Filament v4 con TIPO INVARIANTE** (deben calzar EXACTO con el padre, o fatal de PHP al
    autoload → 500 en el admin): `$navigationIcon` → `protected static string|BackedEnum|null`;
    `$navigationGroup` → `protected static string|UnitEnum|null`; `$columns` de `StatsOverviewWidget` →
    `protected int|array|null` (NO `int|string|array`). Un `?string $navigationIcon` solo también revienta.
    Además `Factory::configure()` DEBE ser `public` (el padre lo es; `protected` = fatal al usar la factory).
    Verificación no-test: `php -r 'require "vendor/autoload.php"; class_exists(\App\Filament\...::class);'`
    autocarga y revela el fatal; barrer todas las props con grep. Detalle en [[filament-v4-component-gotchas]].
17. **Clases Tailwind de blades custom NO aplican sin tema compilado.** Hay que compilar un tema Filament v4:
    `resources/css/filament/admin/theme.css` (`@import` del theme de Filament + `@source` de app / `resources/views/filament` /
    `packages/logistics/*/src/Presentation/Filament`), registrado con `->viteTheme(...)`, y `npm run build` en el HOST.
    El **vendor de Filament vive solo en el container** → copiarlo antes: `docker compose cp app:/var/www/html/vendor/filament vendor/filament`.
    No correr dos `npm install` a la vez (ENOTEMPTY). Si el tema no está compilado, deja `viteTheme` comentado
    (Filament usa su CSS, el admin funciona). Ver [[admin-ui-maps]].
18. **Mapas = Leaflet + OpenStreetMap siempre** (sin Google, sin API key); Leaflet-Geoman para dibujar polígonos.
    Ruteo = OSRM (`/table` matriz, `/route` geometría, `/trip` TSP, `/match` road-snap); env `OSRM_HOST`. El orden de
    paradas es TSP/VRP (Clarke-Wright+2-opt), NO Dijkstra. Geometría de ruta se persiste server-side en `planning_plans.route_geometry`.
    OJO: `file_get_contents` https FALLA en el container (SSL) → usar `Illuminate\Support\Facades\Http` para llamar a OSRM.
19. **RBAC/acceso**: `User::canAccessPanel()` exige rol Admin/Supervisor (`App\Enums\Role`). Tras `migrate:fresh --seed`
    re-asignar el rol al usuario dev (`->assignRole(Role::Admin)`), o queda fuera del admin. Login dev en [[admin-ui-maps]].
20. **Imágenes de producto (ImageColumn/ImageEntry)**: `->circular()` sobre una foto apaisada la recorta en un
    círculo grande (se vio un TV con un círculo de nubes). Usar `->square()->imageSize(40|56)->checkFileExistence(false)`
    (los demos son URLs externas `picsum.photos`). Ver [[filament-v4-component-gotchas]].
21. **Tarjeta de `RepeatableEntry` clickeable (no botón)**: el dueño quiere el recuadro ENTERO clickeable, sin
    botones. Patrón: un `Filament\Schemas\Components\View` oculto por item (su `viewData(fn($record))` recibe el
    record del item) con Alpine `x-init` que hace `$el.closest('.fi-in-repeatable-item')` y le engancha `click`
    → llama una función global expuesta por el blade del mapa (`window.focusRouteStop`); ignora `a, button` para
    que los links de texto naveguen; solo cablea si el item tiene punto (`hasPoint`). Ver [[filament-v4-component-gotchas]].
22. **Mostrar valor legible junto a FK UUID (API y admin)**: resolver en el QueryHandler leyendo el Record del
    otro contexto (monorepo, autoload común; EN LOTE para evitar N+1), pasarlo al ReadModel como arg opcional,
    añadir la clave en `toArray()` y la propiedad nullable en `openapi/v1.yaml` (mismo cambio). No agregar
    dependencia composer entre contextos. CUIDADO con casts `datetime`: el atributo es Carbon, usar
    `->toDateTimeImmutable()` (no `new DateTimeImmutable($record->campo)`, que revienta con 500). Ver [[api-readmodel-enrichment]].

## Gotchas al correr la suite (Pest, verificados 2026-10-13; de 201+9+5 errores a 0)

20. **Colisión de helpers globales entre tests**: Pest carga TODOS los `*Test.php` en un proceso, así que
    una `function foo()` top-level en el test de un package choca con la misma en otro (`fixedClock` fue el
    caso). Nombra los helpers de test con prefijo del package (`capacityFixedClock`) o chequéalos:
    `grep -rhoE "^function [a-z]+" packages/*/tests | sort | uniq -d`.
21. **`->toThrow(Throwable::class)` NO funciona** en Pest (Throwable es interface → lo trata como substring
    del mensaje y falla). Usa la clase concreta de la excepción (`->toThrow(AccessException::class)`).
22. **UUID en columnas**: un test que mete un id legible (`"REC-7001"`) en una columna `uuid` revienta con
    `SQLSTATE[22P02] invalid input syntax for type uuid`. Usa UUIDs válidos en los fixtures (los event_id
    de dominio SON UUIDs).
23. **PHPStan `Collection::all()` NO es `list`**: `$coll->map(...)->all()` es `array<int,T>`, no `list<T>`.
    Para un `@return list<...>` envuelve en `array_values(...)` o declara `@return array<int, ...>`. Y
    `Model::query()->with(...)->find($id)` tipa a `Model|null` (pierde el genérico): tras el null-guard,
    `assert($x instanceof FooRecord);` para angostar.

## Gotchas de read-models de agregación cross-context (historial/analítica, 2026-10-13)

- Un ReadModel que AGREGA cruzando contextos por una **clave implícita** (no hay FK ni entidad "jornada")
  debe indexar por la clave COMPUESTA completa y **deduplicar**. Caso real `GetDriverHistory`: unía
  `fleet_crews` ↔ `execution_driver_routes` por fecha sola → un chofer con dos tripulaciones el mismo día
  contaba la misma ruta/paradas DOS veces (total_stops x2). Fix: indexar rutas por `(vehicle_id, fecha)`,
  preferir la ruta confirmada por `driver_id`, descartar las de otro chofer, y marcar cada ruta como
  consumida (set de ids) para no recontar. Verificar el total contra una sola ruta conocida.
- La atribución fuerte (poblar `driver_id`/`helper_ids` en la ruta de execution) requeriría meter el
  concepto de tripulación (Fleet) en el Domain de Execution; se dejó como columna aditiva opcional y el
  read-model cae al join por vehículo+fecha. En DEMO se alinea con un paso idempotente en el seeder
  (`alignDriverRoutesWithCrews`). Ver [[fleet-personnel-expansion]], [[seed-data-cross-refs]].
- Resolver nombres/placas EN LOTE (whereIn + keyBy), nunca por fila (N+1), igual que el enriquecimiento de FKs.

## Patrones reusables de un contexto (verificados 2026-10-14 en telemetry y fleetops)

- **Alerta periódica por barrido → outbox** (vencimientos, mantenimiento por vencer, etc.): un
  `XScanner` de Application reúne las vistas candidatas (reusado por la query del tablero Y por la alerta,
  mismo criterio), un `CommandHandler` (NO readonly: guarda contadores de la última corrida) emite UN solo
  evento resumen al outbox si hay algo, y un `Command` de consola lo dispara e imprime el resumen. Lo
  agenda el propio ServiceProvider: en `boot()`, dentro de `if ($this->app->runningInConsole())`,
  `$this->commands([...])` + `$this->app->booted(fn () => $this->app->make(Schedule::class)->command('...')->dailyAt('..')->withoutOverlapping())`.
  Molde: `fleet/AlertExpiringDocuments` → copiado a `fleetops/AlertDueMaintenance` y `telemetry:aggregate-streets`.
- **Query transversal/analítica que cruza contextos** (TCO, costo/km): el QueryHandler suma varios repos
  del propio contexto y, para datos de OTRO contexto, lee su TABLA read-only con `DB::table('otra_tabla')`
  envuelto en `try/catch` (degrada a 0 si no existe), no una dependencia composer. Caso: `fleetops`
  `GetVehicleCostSummary` suma combustible+mantenimiento+gastos y divide por `telemetry_trips.distance_m`.
- **VO Money por contexto**: replicar `carriers/Money` (centavos int + currency, `of/add/subtract/multiply/toArray`)
  con la Exception propia del contexto; guardar `*_cents` + `currency`, nunca float. Default BOB en Dismac.
- **Nombre real del Record de otro contexto**: NO asumir `XRecord`; verificar (p. ej. el vehículo de fleet
  es `Logistics\Fleet\...\Model\VehicleModel`, no `VehicleRecord`). Para mostrar su placa, lectura cruzada
  read-only EN LOTE (helper/trait memoizado), nunca belongsTo que obligue a tocar el otro contexto.

## Presentación "operaciones-primero" del admin (verificado 2026-10-14)

El admin NO es un explorador de tablas: es una sala de operaciones. Al añadir presentación a un contexto,
seguir esto (detalle en [[admin-ops-first-redesign]]):
- **Navegación: 5 grupos fijos** (`Operations, Fleet, Network, Commerce, Administration`) declarados en
  `AdminPanelProvider::navigationGroups`. Cada Resource/Page elige su grupo con `getNavigationGroup(): string`
  (`__('Fleet')`) y orden con `getNavigationSort(): int` (usar `: int`, NO `?int` → `return.unusedType`).
  Cockpits/acciones con sort bajo (arriba), catálogos CRUD con sort alto (abajo). No crear grupos nuevos por contexto.
- **Landing = `App\Filament\Pages\ControlTower`** (`extends Dashboard`, `$routePath='/'`), NO el Dashboard
  por defecto (se quitó de `->pages([])`). Widgets en `app/Filament/Widgets` ordenados excepciones→salud→KPIs,
  con el concern `ReadsControlTowerData` (lectura tolerante + `canView()` Admin/Supervisor).
- **Cockpit por frente** = una `Page` que arriba muestra EXCEPCIONES ("qué requiere acción") con su acción
  inline que despacha un Command (reusar el helper `DeliveryResource::dispatch()`), y debajo los KPIs. Las
  Pages de package se registran a mano en `AdminPanelProvider->pages([...])`.
- **Gating**: en el admin solo entran Admin+Supervisor (`User::canAccessPanel`). El grupo **Administration**
  es Admin-only → trait `AdminOnly` en todos sus resources. No relajar los que ya son AdminOnly.
- **Selects**: regla 5 (`->native(false)` + searchable donde referencia entidad) YA cumplida en los 95
  `Select::make`. Para `<select>` nativos hechos a mano usar los dropdowns propios `<x-dismac-select>`
  (Blade/Alpine, sincroniza Livewire) o `resources/js/Components/UI/Select.vue` (SPA). Ver [[admin-ui-maps]].

## Gotchas de verificación (2026-10-14)

- **`composer dump-autoload` aborta** si un Resource nuevo referencia una Page aún no autocargada (el
  `package:discover` del post-dump bootea Filament antes de que el classmap la tenga → "Class not found" →
  artisan deja de bootear). Fix: `composer dump-autoload -o --no-scripts` y luego `php artisan package:discover`.
- **`grep` de un patrón que empieza con `-`** (p.ej. `"->native(false)"`) devuelve **falso 0** (grep lo toma
  como flags). Usar `grep -- "->native(false)"`. Un falso 0 hizo creer que 95 selects eran nativos.
- **Sweep de render del admin**: autenticar admin, iterar `getPages()` + `getResources()::getUrl('index')`
  por el HTTP kernel contando 200. El script temporal va DENTRO del repo (el container NO ve el scratchpad del
  host) y **se BORRA al terminar** — un `_tmp_*.php` olvidado se coló commiteado en la raíz (basura real que
  hubo que `git rm`). Nunca dejar scripts de verificación en el working tree.
- **PHPStan: errores FANTASMA del result-cache.** Un `phpstan analyse` global puede reportar errores que ya
  no existen (quedaron en `.phpstan.cache` de un estado viejo, sobre todo tras `dump-autoload` o con varias
  sesiones editando). ANTES de confiar en un conteo: `./vendor/bin/phpstan clear-result-cache` y re-correr.
  (Esto hizo creer que había 8 errores en fleetops/tracking que en realidad eran 0.) Además, el `phpstan.neon`
  del proyecto tiene `treatPhpDocTypesAsCertain: false`: NO emite `notIdentical.alwaysTrue`/`alwaysFalse`
  derivados de tipos PHPDoc → una guarda `!== null` sobre un retorno `?T` declarado por PHPDoc es LEGÍTIMA,
  no muerta; no la quites "para limpiar PHPStan" (no la marca) ni asumas bug.
- **No persigas el Pint/PHPStan de una sesión concurrente VIVA.** Si otra sesión está escribiendo un contexto
  (p.ej. planning) en tiempo real, arreglar sus stragglers de formato uno por uno NO converge (aparecen
  nuevos) y arriesga clobber. Formatea en bloque el paquete una vez (`pint packages/logistics/<ctx>`) o déjalo
  a su dueño; ver [[multisession-shared-tree]]. Lo tuyo se mide sobre TUS archivos.
- **Mapas**: usar SIEMPRE el helper `window.DismacMaps.tileLayer(L, {url, fallbackUrl, ...})` (public/js/dismac-maps.js),
  nunca `L.tileLayer()` crudo — cae al OSM público si el tileserver self-host no responde. Ver [[admin-ui-maps]].

## Gotchas de olas de agentes (2026-10-14)

- **La regla "no ejecutar tests" fue DEROGADA.** La DoD (CLAUDE.md #1) exige correr Pest de verdad:
  `docker compose exec -T app ./vendor/bin/pest` + PHPStan 8 + Pint + verificación real. Reportar números
  (tests/aserciones). (Un agente de verify obedeció una memoria obsoleta y SALTÓ pest dejando la ola "verde"
  sin correr la suite; la memoria se borró.)
- **i18n = causa real del "spanglish".** El admin se muestra en español vía `lang/es.json`; toda clave `__()`
  SIN entrada en es.json se renderiza en inglés. Al agregar texto visible: `__()` fuente en inglés + par en
  es.json. Los tests de render del admin DEBEN afirmar `__('Key')` (locale es), NUNCA el literal inglés
  (se rompen en cuanto se traduce la clave). Ver [[filament-v4-component-gotchas]] gotcha 5.
- **Documentos polimórficos de flota**: `fleet_documents` (owner_type vehicle|driver|helper) + el helper de
  presentación `OwnerDocuments` se comparten entre los 3 dueños; agregar un dueño nuevo = extender ese helper,
  no duplicar. Campo `reference` para nº de póliza/serie (SOAT) o certificado (inspección).
- **Scope multi-eje con índices únicos PARCIALES**: cuando una tabla puede scopearse por exactamente UNO de
  varios ejes nullables (p.ej. `capacity_buckets` por municipio | zona | nodo), la clave natural va con
  índices únicos PARCIALES por eje (cada uno exige NULL en los otros), no un unique compuesto simple.
- **Activación masiva por rango = job encolado y troceado**: generar N×M filas (fechas × scopes) va async
  (`ShouldQueue`+`ShouldBeUnique`, `afterCommit`) con chunking y re-encolado por cursor, cada fila idempotente
  por su clave natural. No hacerlo síncrono en el request. Ver [[dispatch-calendar-municipality]].
- **Coordinación entre sesiones paralelas en el mismo working tree**: particionar por archivos, dueño único
  de los archivos calientes (es.json/openapi/compose/providers.php), serializar composer/npm/pest, no dos
  pest a la vez. Olas de ~5 subagentes concurrentes queman el límite de sesión. Ver [[multisession-shared-tree]].

## Gotchas de CI (Docker / Vite) — no se reproducen en dev (2026-10-15)

El CI (`.github/workflows/ci.yml`) falla por cosas que LOCALMENTE andan, porque en dev ya existen
`vendor/`, `node_modules/` y `public/build/`. Verificar como el runner: `git worktree add --detach
/tmp/ci-verify <sha>` y buildear/probar ahí (solo archivos commiteados). Detalle en [[ci-docker-vite-gotchas]].

- **Build de la imagen prod (job `image`)**: el tema Filament hace `@import 'vendor/filament/...'` y Tailwind
  `@source 'vendor/laravel/...'`, pero `vendor/` está en `.dockerignore` → el stage `assets` (solo Node) no
  lo tiene y `vite build` falla. Fix: stage `vendor` (`FROM base`, `composer install`) + `COPY --from=vendor
  /var/www/html/vendor ./vendor` en `assets` antes de `npm run build`.
- **Tests de render de página completa (job `test`)**: `$this->get('/admin/...')` renderiza el layout con
  `@vite`; el CI no compila assets → `public/build/manifest.json` no existe → `ViteManifestNotFoundException`
  (500). Fix: `$this->withoutVite()` en `tests/TestCase::setUp()`. (`Livewire::test()` no dispara esto.)
- **Deps JS**: un `import 'x'` en resources/js sin su entrada en package.json/lock → `npm ci` no la instala y
  `vite` falla ("Rolldown failed to resolve import"). Commitear la dep (package.json + package-lock.json).
- **git push** lo bloquea el clasificador de auto-mode; `gh` no está instalado → PR por link de compare.

## Referencia

`packages/logistics/network`, `fleet`, `coverage`, `shipments` ya siguen este patrón; `labels` para el
estilo de VOs y comandos; `outbox` y `settings` para persistencia e idempotencia. `fleetops` (mantenimiento/
gastos/perfil de vehículo) es un contexto nuevo agregado en esta fase.
