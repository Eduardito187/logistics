# Normalización y dedup de datos repetidos a escala (WS3)

Objetivo del dueño del proyecto: la base crecerá a gigas; hay datos repetidos, **sobre todo
geolocalizaciones de los mapeos**. Este documento registra qué repetición se eliminó, qué se deja como
propuesta y por qué, con el ahorro estimado y el riesgo de cada caso.

Principio rector (no negociable): **no se normaliza cruzando bounded contexts**. Cada contexto es
autónomo y extraíble a microservicio (ADR 001/008). Un dedup que una dos contexts por una tabla
compartida rompe ese diseño. Si un contexto quiere reusar datos de otro, se expone por un **contrato**,
nunca compartiendo tabla.

---

## 1. Implementado — Dedup persistente de geocoding (dirección → coordenada)

**Contexto:** `shipments` (donde vive el contrato `Geocoder`).

**Problema:** cada resolución de la MISMA dirección repegaba a Nominatim/OSM. La caché Valkey existente
(`NominatimGeocoder`) tiene TTL y se pierde en reinicios/purgas, y no mide la repetición. En reprocesos
masivos y re-geocodificaciones (comando `GeocodeShipmentAddress` vía outbox) la misma dirección se
resolvía una y otra vez.

**Solución:** decorador `CachingGeocoder` (Infrastructure) + tabla normalizada `geocoding_cache`
(migración `2026_10_20_000700_create_geocoding_cache_table`). Antes de pegarle al proveedor, busca por
`query_hash`; si hay fila, reconstruye el `GeocodeResult` sin I/O e incrementa `hits`/`last_hit_at`; si
no, resuelve y persiste.

**Cómo agrupa la repetición (clave):** la consulta se normaliza —`texto [+ ", " + referencia]`,
colapso de espacios (`\s+` → 1), `trim`, minúsculas Unicode— y el hash es
`sha1(proveedor | país | consulta_normalizada)`. Así `"Av. Arce 2345"`, `"  av.   arce 2345 "` y
`"AV. ARCE 2345"` caen en una sola fila. El proveedor y el país entran al hash para no mezclar cachés
de distintos geocoders/regiones.

**Capas (de rápida a durable):** Valkey (en `NominatimGeocoder`, TTL, por delante) → `geocoding_cache`
(DB, dedup persistente + métrica `hits`) → HTTP a Nominatim. No se añadió una segunda capa Valkey en el
decorador: el lookup por PK-hash es barato y el acierto ya requiere un write (`hits`), así que la tabla
sola es la fuente dedup correcta.

**Ahorro estimado:** elimina el 100% de las llamadas HTTP repetidas a Nominatim para direcciones ya
vistas (clientes recurrentes, reprocesos, backfills). En un piloto con ~N pedidos/día y tasa de
direcciones repetidas r, ahorra ~r·N requests/día y acelera cada reproceso de O(HTTP ~1 req/s con
throttle OSM) a O(lookup indexado). La columna `hits` expone r real para medirlo.

**Riesgo:** bajo. Tabla aditiva; `insertOrIgnore` evita choques entre workers del outbox; si una fila
quedara de un esquema anterior, `fromRow()` devuelve null y se re-resuelve. `NullGeocoder` NO se
envuelve (cachearlo ensuciaría la tabla con respuestas sintéticas). Config:
`SHIPMENTS_GEOCODER_PERSISTENT_CACHE` (default true) lo apaga si hiciera falta.

---

## 2. Propuesta — Dedup de reverse-geocoding (coordenada → dirección)

**Estado:** NO implementado. **No existe hoy ningún consumidor** de reverse-geocoding en el código
(`grep` de `reverse`/`/reverse` en todos los packages: sin resultados). Telemetry guarda lat/lng crudos
en los pings pero nunca los convierte a dirección; planning usa OSRM para matrices/geometrías, no
geocoding inverso.

**Recomendación:** crear la tabla solo cuando aparezca el primer flujo real (p. ej. "mostrar la calle
donde está detenido el camión"). Debe vivir en el contexto que lo consuma (previsiblemente `telemetry`),
con su PROPIO contrato `ReverseGeocoder` y su propia tabla `reverse_geocoding_cache` — NO reusar
`geocoding_cache` de shipments (sería acoplar dos contexts). Clave de dedup sugerida: hash de
`(lat, lng)` redondeados a una rejilla (p. ej. 4–5 decimales ≈ 11 m / 1 m) para agrupar coordenadas casi
iguales. Crearla ahora sería una tabla muerta; por eso queda como propuesta.

---

## 3. Análisis de otras repeticiones costosas a escala

### 3.1 Matrices OSRM (planning) — YA resuelto
`OsrmRoadMatrixProvider` ya cachea en Valkey la matriz por hash del conjunto de coordenadas (la matriz de
un mismo conjunto no cambia). No requiere acción. Posible mejora futura: capa persistente análoga a
`geocoding_cache` si el TTL de Valkey provoca recomputar matrices caras tras purgas; evaluar solo si la
métrica lo justifica.

### 3.2 Estadísticas de calle (telemetry) — YA resuelto
`telemetry_street_stats` ya deduplica por `segment_key` (hash de los nodos OSM del tramo, `unique`):
acumula muestras sobre una sola fila por tramo. Es el patrón correcto; no requiere acción.

### 3.3 Geometría de ruta `planning_plans.route_geometry` (jsonb) — dedup NO recomendado
La polilínea GeoJSON se guarda por plan (vehículo + día). Dos planes con geometría byte-idéntica son
raros (distinto conjunto/orden de paradas), así que un dedup por hash a una tabla
`route_geometries(hash → geometry)` daría un acierto marginal a cambio de un join extra en cada pintado
de mapa y de invalidación al recalcular. **Ahorro estimado:** bajo. **Riesgo:** medio (complejidad +
join en ruta caliente del mapa). **Recomendación:** no deduplicar. Si el peso en disco molesta, atacarlo
por **retención** (purgar geometría de planes cerrados antiguos) o compresión de la columna, no por
normalización.

### 3.4 Pings GPS `telemetry_pings` (lat/lng por fila) — no es caso de dedup
Son millones de filas append-only y cada ping es, por naturaleza, distinto (otra marca de tiempo). La
"repetición" de columnas lat/lng no se elimina normalizando: cada lectura es única. El crecimiento se
controla con **retención + rollup**, que el diseño ya contempla (`telemetry_vehicle_positions` y
`telemetry_trips` resumen la traza). **Recomendación:** definir política de retención de pings crudos
(p. ej. 30–90 días) y, si aplica, **particionado por tiempo** de la tabla; no normalizar coordenadas.
Fuera del alcance de dedup de WS3.

### 3.5 Direcciones duplicadas entre envíos del mismo cliente (shipments) — dentro del agregado
El mismo cliente repite dirección entre pedidos; `shipments` guarda `address_text` + `lat/lng` embebidos
por fila (la dirección es un Value Object del agregado Shipment, no una entidad propia). La repetición
**cara** aquí era la re-geocodificación, y eso ya lo elimina `geocoding_cache` (§1): aunque el texto se
repita en N filas, se geocodifica una sola vez. Extraer las direcciones a una tabla `addresses`
referenciada por FK normalizaría el texto, pero: rompe la simplicidad del agregado, añade joins al
listado caliente y no ahorra el costo real (ya resuelto). **Ahorro estimado** del texto duplicado: bajo
frente al tamaño total de la fila de envío. **Riesgo:** medio-alto (toca el agregado y el read-side).
**Recomendación:** no normalizar; mantener la dirección embebida y apoyarse en `geocoding_cache` para el
costo de I/O.

---

## Resumen

| Caso | Acción | Ahorro | Riesgo |
|------|--------|--------|--------|
| Geocoding dir→coord (§1) | **Implementado** (`geocoding_cache` + decorador) | Alto (elimina HTTP repetido a OSM) | Bajo |
| Reverse-geocoding (§2) | Propuesta (sin consumidor hoy) | — | — |
| Matrices OSRM (§3.1) | Ya resuelto (Valkey) | — | — |
| Street stats (§3.2) | Ya resuelto (segment_key) | — | — |
| Geometría de ruta (§3.3) | No deduplicar; retención | Bajo | Medio |
| Pings GPS (§3.4) | Retención/particionado, no dedup | — | — |
| Direcciones en envíos (§3.5) | No normalizar; ya cubierto por §1 | Bajo | Medio-alto |
