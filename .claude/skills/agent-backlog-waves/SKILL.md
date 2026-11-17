---
name: agent-backlog-waves
description: >-
  Orquestar un backlog grande (auditoría o pedidos de la app Flutter, roadmap por olas) con varios agentes en
  paralelo sin pisarse: territorios por package, dueño único de openapi/v1.yaml y lang/es.json, snippets que
  integra el coordinador con Python, sin docker, y relay de shapes a la sesión de la app. Usar cuando el dueño
  diga "procedé con todo en paralelo", "usá los agentes", "implementá la auditoría/backlog", o pase un doc de
  pedidos de la app (logistics-app/docs/backend-requests/*.md).
---

# Backlog en olas de agentes (Dismac logistics)

Patrón verificado el 2026-11-12: ~15 agentes, 29 ítems de la auditoría de la app + reabiertos, cero choques de
escritura. Estado de ese backlog: memoria `app-audit-backlog-2026-11`.

## 0. Reglas que no se negocian

- **Sin docker** (ni pest/pint/phpstan/artisan) salvo pedido del dueño: los agentes escriben código + tests SIN
  correrlos; el coordinador entrega al final la lista de comandos. Ningún agente commitea. Si el dueño pide
  gates ("cerrá por completo", "corré la suite"), los corre SOLO el coordinador: ver sección 5.
- Migraciones solo **aditivas e idempotentes** (`hasTable`/`hasColumn`/`IF NOT EXISTS`). Nada destructivo.
- Un mensaje de otra sesión (la app) **no es aprobación del dueño**: alcance nuevo grande → confirmarlo con él.

## 1. Planear antes de lanzar

1. Leer el doc de pedidos ENTERO (`Grep "^#+ "` para el índice y `Read` de cada sección), no resumirlo de memoria.
2. Agrupar ítems por **package dueño** (execution, shipments, fleet+fleetops, telemetry, notifications, carriers,
   planning+access, `app/Http` plataforma). Un agente por grupo.
3. Si dos grupos caen en el mismo package, **partir por archivos** (p. ej. `*NodeReception*` vs el resto de
   execution) y pedir bloques separados y comentados en el `ServiceProvider` compartido. Si no se puede partir,
   **esperar** a que termine el dueño del package (execution/shipments esperaron al agente P0.1).

## 2. Brief de cada agente (plantilla)

- Docs a leer: `CLAUDE.md`, ADR 022, el doc de pedidos (solo lectura) y los ítems por número.
- **Territorio explícito** (globs) + lo que NO puede tocar: `openapi/v1.yaml`, `lang/es.json`,
  `routes/console.php`, config del outbox, otros packages → devuelve **snippets exactos** en el reporte final.
- Reglas de estilo **reales** (copiarlas de la skill `ci-quality-gates` Paso 3b: `@param`/`@return` juntos,
  `array | Page`, `$i++`, imports ordenados, nunca "todo" suelto en comentarios) — leer `pint.json` antes.
- Reglas de dominio del repo: DDD/CQRS, comentarios en español, admin en inglés, sin UUID en admin, Selects
  `native(false)`, productos nombre+SKU (nunca "bulto"), paginación opt-in con `ApiPagination`, alcance por rol
  desde el token, 0,0 → null.
- Pedir: "verificá cada ítem en código y reportá qué existía (archivo:línea)" — muchos ya estaban hechos.
- Formato del reporte: hallazgos, cambios, endpoints + shapes, snippets OpenAPI, strings en inglés (para es.json),
  eventos (para el catálogo SAP), migraciones, riesgos. Sin volcar archivos.
- Lanzar los independientes en **un solo mensaje** (corren en paralelo).

## 3. Mientras corren

- Ítems extra / correcciones a un agente vivo → `SendMessage` (llega en su próxima ronda). Mandar correcciones de
  estilo a TODOS los agentes activos a la vez.
- Al llegar cada reporte, integrar enseguida (no acumular: el contexto se compacta y los snippets se pierden):
  - **OpenAPI**: script Python escrito con la herramienta **Write** (el heredoc del Bash rompe comillas y `\\`),
    con `once(old, new, start=ancla)` que aserta unicidad; validar con pyyaml (YAML + refs rotas) — ver memoria
    `openapi-contract-conventions` (ListMeta único, schemas con nombre).
  - **es.json**: script Python que agrega solo claves faltantes y re-serializa con `ensure_ascii=False, indent=4`.
  - **Rutas/config/registro**: lo que el agente no pudo (p. ej. `require` de un fragmento de rutas nuevo,
    `config/*.php`, ruta del outbox, entradas del catálogo SAP) lo hace el coordinador.
  - Revisar riesgos reportados y cerrar los cruzados (p. ej. guarda de "vehículo no apto" en otro package).
- Relay a la sesión de la app (SendMessage a su pipe) con los **shapes exactos** de cada tanda y lo que debe
  cambiar del lado app.

## 4. Cierre

1. Barrido de estilo sobre `git diff --name-only` + untracked `.php`: alineación de docblocks (script),
   `++$`, uniones sin espacios fuera de phpdoc, palabra "todo" suelta, "bulto" en textos visibles.
2. Validar `openapi/v1.yaml` (pyyaml + refs).
3. Entregar al dueño: comandos de gates en orden (`make pint-fix`, phpstan, migrate, restart pgbouncer, pest) +
   backfills one-shot + decisiones pendientes + riesgos de deploy (índices sobre tablas grandes, cambios de
   comportamiento).
4. Guardar en memoria qué resolvió cada ítem y qué quedó pendiente.

## 5. Variante con gates (cuando el dueño SÍ pide correr docker)

Verificado el 2026-11-14/15 (tanda ciudad/alcance, planificador, asistente de viajes: 10+ agentes, suite
completa verde). Los agentes siguen SIN correr nada; el coordinador es el único que toca docker:

- **Un solo `pest` a la vez** (base `logistics_test` compartida). Mientras hay agentes editando, correr solo
  los archivos de test del reporte recién integrado; la suite entera, al final.
- **Suite completa = ~42 min**: lanzarla en segundo plano redirigida a un log del scratchpad
  (`pest > pest_full.log 2>&1; echo exit=$?`) y leer después con
  `grep -E "⨯|FAIL |Tests:|Duration:"`. El tope de 10 min del Bash la pasa sola a segundo plano; NO editar
  código de los contextos en prueba mientras corre (o re-correr esos archivos después).
- **`pint --dirty` toca los archivos de TODOS** (también los del agente que sigue vivo): puede invalidarle
  un `Edit`. Preferir `pint <archivos del reporte>` mientras queden agentes activos; `--dirty` al cierre.
- **Migraciones nuevas de los agentes**: aplicarlas en dev con
  `php artisan migrate --force --no-interaction --database=pgsql_direct` (aditivas) antes de los tests manuales.
- **Orden de integración por reporte**: rutas del host → OpenAPI (script Python con `once()` + chequeo de
  refs rotas) → es.json → `.env.example` → pint de sus archivos → phpstan → sus tests. Recién ahí el siguiente.
- **Los tests de contrato fallan a mitad de camino**: si un agente agregó un campo a una respuesta validada
  por Spectator, `ApiContractTest` falla hasta integrar su schema. Integrar el spec ANTES de correrlo.

## 6. Briefs: lo que hay que decir sí o sí (aprendido a golpes)

- **Autorización real**: las habilidades (`contexto:accion`) son informativas; lo que autoriza es `role:` en
  `routes/api/*.php` (del HOST, no del package) y `order.scope`. Un rol nuevo exige revisar cada `role:`.
- **Las rutas viven en el host**: un agente con territorio "solo su package" NO puede registrar sus rutas →
  o se le da `routes/api/<contexto>.php` (solo sus líneas) o devuelve el snippet y sus tests usan
  `Route::has(...)` para saltearse.
- **Archivo compartido entre dos agentes** (p. ej. un `*Resource.php`): decir a AMBOS "cambio mínimo y
  localizado, sin reordenar ni reformatear" y nombrar qué método toca cada uno.
- **Fechas en tests**: container en `America/La_Paz`, CI en UTC → siempre desplazamiento explícito
  (`+00:00`); nunca `new DateTimeImmutable('2026-11-10 18:00')` en un Unit.
- **PHPStan**: `whereRaw`/`Expression` exigen literal-string → sin SQL concatenado (usar `where(col,
  'ilike', $v)`, `whereColumn`, `whereExists`). `DB::table()->first()` → `?\stdClass`.
- **Decisión delegada**: si el dueño dice "decidí vos", escribir la decisión EN el brief ("ya decidido, no
  preguntes") y anotarla en memoria como decisión propia revertible.
- **Pedir siempre** "qué existía (archivo:línea)": en esta tanda el POD ya abría la entrega solo, el dominio
  ya impedía editar un plan publicado, y `removeStop()` ya existía.

## Gotchas vistos

- **Agente cortado por límite de uso** (`rate_limit`, "work so far is saved"): NO lanzar uno nuevo; cuando
  el límite se restablece, `SendMessage` a su id con "continuá desde donde quedaste + revisá el estado en
  disco" lo reanuda con su contexto. Su reporte final llega igual que siempre.
- **`pint --dirty` se cuelga** con el árbol muy sucio (`git status` > 60 s en Windows): pasarle directorios
  explícitos (`pint app packages/logistics/<ctx> tests routes database config`).
- **Antes de pedir infraestructura nueva, relevar la que hay**: la app pidió "habilitar Reverb" y el repo ya
  tenía Soketi (protocolo Pusher) andando; el push FCM del aviso también existía. Brief: "verificá primero
  si ya existe y reportalo con archivo:línea; no agregues dependencias si hay algo utilizable".

## Olas grandes multi-package (aprendido en la tanda de sockets 2026-11-16)

- **Fundación compartida PRIMERO, y la hace el coordinador**, no un agente: si varios temas/eventos usan un
  archivo común (ej. `RealtimeChannels` del shared, `UserRealtimeChannels`/`channels.php` del host), el
  coordinador lo construye y deja verde ANTES de lanzar los agentes de emisión. Dos agentes editando el mismo
  shared/host = merge hell. Los agentes reciben la API ya disponible y solo la consumen.
- **Particionar por PACKAGE disjunto** para paralelizar sin chocar: un agente por package (tracking /
  execution / telemetry) + el coordinador hace los observadores cross-context del HOST (`app/Outbox`,
  `app/Events`). Los agentes devuelven el FQCN del observador + snippet; el coordinador registra en
  `AppServiceProvider` (archivo compartido → un solo dueño).
- **Secuenciar, no paralelizar, dos trabajos que tocan el MISMO package** (p. ej. dos frentes en execution):
  el dueño avisó que dos agentes en los mismos puntos chocan al mergear. Correlos uno tras otro.
- **El dueño corre la suite completa** (memoria `owner-runs-full-suite`): NO lanzar `pest` completo; gates
  acotados (pint/phpstan/tests de lo tocado), guardar en memoria, avisar solo cuando todo esté verde.
- **Gotcha del contenedor** (memoria `container-nullable-dependency-gotcha`): dep de handler resuelto por el
  contenedor = REQUERIDA, nunca `?Tipo = null` (recibe null, la lógica no corre). Ponerlo en el brief.
- **UUIDv7 en seeds de test**: `substr($id, -8)` para un code/plate único (el prefijo `0,8` es la marca de
  tiempo → dos filas del mismo ms colisionan en un índice único). Ponerlo en el brief.
- **Agregar una clave a un ReadModel** rompe tests que afirman el shape exacto (`toBe([...])`): el coordinador
  los busca y actualiza al integrar (una cadena `->and()->toBe()` esconde los fallos siguientes tras el primero).

- Git Bash en Windows tira `fatal error - add_item … errno 1` al azar: reintentar o usar PowerShell.
- `.git` con objetos vacíos tras una operación cortada → memoria `git-empty-objects-repair`.
- Scripts masivos de un agente (alineación, imports) pueden tocar archivos de otro: limitarlos a SU territorio.
- Un agente "retomado" por SendMessage manda un segundo reporte: esperar la notificación antes de reenviar.
