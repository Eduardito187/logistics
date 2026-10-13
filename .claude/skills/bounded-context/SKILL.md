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

## Referencia

`packages/logistics/network`, `fleet`, `coverage`, `shipments` ya siguen este patrón; `labels` para el
estilo de VOs y comandos; `outbox` y `settings` para persistencia e idempotencia.
