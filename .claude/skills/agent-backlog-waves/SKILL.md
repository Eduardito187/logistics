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
  correrlos; el coordinador entrega al final la lista de comandos. Ningún agente commitea.
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

## Gotchas vistos

- Git Bash en Windows tira `fatal error - add_item … errno 1` al azar: reintentar o usar PowerShell.
- `.git` con objetos vacíos tras una operación cortada → memoria `git-empty-objects-repair`.
- Scripts masivos de un agente (alineación, imports) pueden tocar archivos de otro: limitarlos a SU territorio.
- Un agente "retomado" por SendMessage manda un segundo reporte: esperar la notificación antes de reenviar.
