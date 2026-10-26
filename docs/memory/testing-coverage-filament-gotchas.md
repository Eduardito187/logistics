---
name: testing-coverage-filament-gotchas
description: "Gate de cobertura --min=75 de CI, cómo se subió 30.6%→81.3%, y gotchas de testear acciones/forms de Filament v4 en Pest"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-15T06:20:30.635Z
---

El job `test` de CI corre `./vendor/bin/pest --coverage --min=75` (pcov). El `<source>` de `phpunit.xml`
mide **todo** `app/` + `packages/logistics/*/src` (sin `<exclude>`). El dueño decidió (2026-10-15) NO acotar
el scope ni bajar el umbral: se **escriben tests** hasta pasar 75%. Presentation (Filament) es ~41% del
código, así que SÍ hay que cubrir Resources/Pages/Widgets, no solo Domain/Application.

Cómo se subió de 30.6% a **81.3%** (olas de agentes en paralelo, un bounded context por agente):
- **Render Filament** = el mayor ROI y bajo riesgo: `Livewire::test(ListXxx::class)->assertOk()` ejecuta
  los closures de `table()`/`form()`/`schema()`/`infolist()`. List/Create no necesitan datos; Edit/View
  siembran un registro con la factory del contexto y pasan `['record' => $m->getKey()]`.
  Auth: `Filament\Facades\Filament::setCurrentPanel('admin')` + `User::factory()->create()->assignRole(Role::Admin)` + `actingAs`.
- **Controllers HTTP** (`/api/v1/...`): `withoutMiddleware()` + `actingAs` (patrón de execution/CoverageHttpLifecycleTest).
- **Controllers Inertia del SPA** (`/app/*`, middleware `auth`): `actingAs(admin)->get('/app/...')->assertOk()`; los `show(string $id)` usan findOrFail → sembrar el registro.
- **Backends con I/O externo** (OSRM/VROOM/Magento): `Http::fake([...])`; el cliente se resuelve del contenedor (`app(\Illuminate\Http\Client\Factory::class)`), mismo singleton que falsea Http::fake.
- **Seeders**: `$this->seed(XxxSeeder::class)` + assert conteo + idempotencia.

GOTCHAS que costaron depurar (ver [[filament-v4-component-gotchas]]):
- **El form de una acción Filament v4 NO es driveable por los helpers de test**: `callAction($name, data: [...])`
  y `setActionData()` llenan el form de la PÁGINA, no el de la acción montada → los campos sin `->default()`
  quedan null y falla la validación `required`. Un `Select->searchable()` además **descarta** el valor seteado
  si no está en su set de opciones cargadas (error "required" aunque `getOptionLabelUsing` resuelva).
  Solución: montar la acción para cubrir su `schema()` (`mountAction('x')->assertActionMounted('x')->assertOk()`)
  y cubrir el EFECTO despachando el Command real por `CommandBus` (handler + repo + mapper). Un campo que la
  acción fija vía su propio `->fillForm(fn($arguments))` SÍ se puede alimentar con `mountAction('x', ['campo'=>$v])`.
- **`use ClaseGlobal;` en archivos de test SIN namespace** dispara el warning "has no effect" que revienta la
  colección (sobre todo en `--parallel`). Usar FQN inline (`\DateTimeImmutable`). Pint `fully_qualified_strict_types`
  puede reintroducirlo; correr `pint` (fix) y re-correr la suite.
- Statuses en DB van en MAYÚSCULAS (`PROPOSED`/`PUBLISHED`/`RECEIVED`); ids a columnas uuid deben ser UUID válidos (no `'test'`/`'veh-1'`).
- `MessageTemplateRecord` tiene unique (message_type, channel, locale): variar `message_type` al crear varios.
- Coverage local puede **crashear (EXIT 255) por memory_limit** al generar el reporte de texto; CI usa
  `memory_limit=-1`. Verificar con `-d memory_limit=3G` o con `--coverage-clover`.

Modelo multi-sesión: los agentes SOLO escriben tests (archivos nuevos, nombres únicos `CoverageXxxTest.php`),
NO corren pest (la DB `logistics_test` es compartida y colisiona); el orquestador corre la suite central y
serial. Límite de sesión (429) tumba agentes Opus en paralelo: reintentar tras el reset o hacerlo el orquestador.
