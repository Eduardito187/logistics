---
name: no-uuid-in-admin
description: Regla dura del dueño — NUNCA mostrar UUIDs en el frontend/panel; resolver FK a su valor legible
metadata:
  node_type: memory
  type: feedback
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-22T02:31:18.818Z
---

El dueño es TAJANTE: **ningún UUID visible en el panel Filament** (ni en la app). Donde haya un id/relación,
mostrar el **valor legible** de la relación (nombre/código/placa/referencia), nunca el UUID.

**Por qué:** un UUID crudo no le dice nada al operador y se ve mierdero; el panel es la herramienta de trabajo.

**Cómo aplicarlo (patrón del proyecto, ver [[api-readmodel-enrichment]]):**
- FK `*_id` → resolver a etiqueta con helpers memoizados por request (lectura directa cross-context, sin
  acoplar Eloquent): `VehiclePlates` (vehicle_id→placa, en fleetops y telemetry), `NodeNames`
  (node_id→"name (code)", en execution/coverage/telemetry), `UserNames` (user_id→nombre, execution),
  `UnitNames` (unit_id→"external_id · unit N/M", execution). Para seller/party/almacén: nombre desde
  sellers/network_nodes. En columnas/entries Filament: `->formatStateUsing`/`->getStateUsing`/`->state(fn)`.
- **Selects**: el `id` va como VALOR interno pero la etiqueta visible y la búsqueda son por el nombre real
  (`getOptionLabelUsing`/`getSearchResultsUsing`/`options` legibles). Ej: el `zone_id` de la cotización en
  PromiseResource pasó de TextInput (pegar UUID) a Select buscable por nombre de zona.
- **PK `id` propia**: ocultarla del display, o mostrar una referencia legible (ej. settlement `id` →
  "seller · período"). Nada de columnas ocultas-pero-activables `copyable` con el UUID.
- **Títulos de registro**: `getRecordTitle`/`recordTitleAttribute` nunca devuelven `id`/`*_id` crudo.
- **Fallbacks**: prohibido `?? $state`/`substr($uuid,0,8)` que filtren el UUID; usar "Unknown X" / "—".

**NO son UUID (son identificadores humanos, SE DEJAN):** `tax_id` (NIT), `national_id` (CI),
`customer_document_id`, `external_id` (referencia de pedido), `order_id` (nº de pedido en labels), `plate`,
`vin`, `license_number`, `token` de tracking, `key`/`token_prefix` de access.

**Auditoría:** grep de `(TextColumn|TextEntry)::make('*_id'|'id')` sin resolver, `recordTitleAttribute('id')`,
fallbacks a id, y blades de páginas custom que impriman ids. Se barrió repo-wide (~56 columnas) y quedó 0.
