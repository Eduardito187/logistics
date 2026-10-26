---
name: dispatch-calendar-municipality
description: "Calendario de despacho por municipio: 7 días, feriados/bloqueos, activación de cupos por rango, y enforcement"
metadata:
  node_type: memory
  type: project
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-15T00:49:08.737Z
---

El dueño pidió (2026-10-14) un calendario de despacho "en grande": 7 días de la semana con posibilidad
de bloquear días (feriados u otros motivos), activar cupos por RANGO de fechas (p.ej. hasta noviembre),
todo POR MUNICIPIO. Implementado en `capacity` + `coverage` + enforcement en `promise`/`planning`.
Verificado verde (Pest 1109→1119, PHPStan 8, Pint). Ver [[routing-geo-rule]] y [[roadmap-build-plan]].

**Eje municipio.** `capacity_buckets` ganó `municipality_id` (uuid nullable, index). `BucketScope` ahora
representa EXACTAMENTE uno de tres ejes (municipio | zona | nodo), con índices únicos PARCIALES por scope
(cada uno exige NULL en los otros dos). `coverage` expone `MunicipalityLocator` (PostGIS, punto→municipio;
como `coverage_geo_areas` no tiene geometría propia, la extensión del municipio = unión de sus
`coverage_zones.area` vía ST_Contains + CTE recursivo subiendo `parent` hasta level='municipality') y
`ListMunicipalities` (catálogo con departamento resuelto en una query, sin N+1) para selects buscables.
Nuevo id tipado `MunicipalityId` para que capacity referencie municipios sin acoplarse a GeoArea.

**Feriados/bloqueos.** Tabla `dispatch_blackout_days` (blackout_date, municipality_id nullable=global,
reason, unique [date, municipality_id]). Agregado `DispatchBlackoutDay` + comandos BlockDispatchDay/
UnblockDispatchDay (idempotentes, outbox) + servicio `DispatchDayAvailability` (un bloqueo GLOBAL tapa a
todos los municipios). Read-side `ListBlackoutDays` / `CheckDispatchDayQuery`.

**Calendario 7 días.** `CapacityRuleset` soporta los 7 días con ventanas por día de semana; config
versionada POR municipio en settings (`capacity.dispatch_rules.municipality.<uuid>` con fallback a la red),
vía `currentForMunicipality()` en `SettingsCapacityRulesetProvider`.

**Activación por rango (asíncrona).** Comando `ActivateCapacityWindow(municipalityIds[], from, to,
weekdays[], windows[{time_slot,unit,quantity}])`: valida, AVANZA el horizonte activo por municipio
(agregado `ActiveHorizon`, solo hacia adelante) y ENCOLA un job (`ActivateCapacityWindowJob`,
ShouldQueue/ShouldBeUnique, onQueue 'planning', afterCommit) que TROCEA por lotes (CHUNK_DAYS=14, se
re-encola con cursor) y por cada fecha elegible (weekday activo y NO bloqueada) despacha `DefineCapacity`
idempotente (clave natural con municipio). Horizonte leíble con `GetActiveHorizon`. "Todos los municipios"
se expande en la capa que dispara (capacity no enumera coverage; un bucket exige exactamente un eje).

**Enforcement.** `ConsumeCapacity` rechaza consumir en día bloqueado (propio o global) o fuera del
horizonte activo del municipio (si no hay horizonte, no restringe, por compatibilidad). En `promise`,
`DispatchDayGate` + adaptadores capacity-backed omiten slots bloqueados/fuera de horizonte (memoizado por
municipio+día). En `planning`, `BlackoutAwarePendingDeliveryProvider` descarta candidatos cuyo día de plan
sea feriado del municipio (resuelto por MunicipalityLocator). GOTCHA conocido: `CachingAvailabilityProvider`
cachea 60s por `CapacityGenerationKey` que NO cambia ante un blackout/horizonte nuevo → la lista cacheada
de slots puede estar hasta 60s desactualizada; el path no cacheado (CalculateDeliveryPromise) y el rechazo
en ConsumeCapacity son inmediatos.

**UI (DispatchCalendar page).** Selector de municipio buscable (ListMunicipalities), vista SEMANAL de 7
días con navegación, total/usado/disponible por día+ventana, días bloqueados en rojo (con motivo) y
fuera-de-horizonte en ámbar (CheckDispatchDayQuery), acción "Activate slots" (municipio/rango/días/ventanas)
y gestión de feriados. El select carga el catálogo completo (buscable client-side; OK para ~340 municipios
de Bolivia; a miles → getSearchResultsUsing server-side).
