# Reporte — Optimización de base de datos a escala (2026-10-20, ola 2)

**Objetivo:** la DB se estima en gigas en semanas. Optimizar al máximo: crons de mantenimiento de las
tablas más transaccionales, purga de "logs" en la base, estrategia para las tablas que más crecen, y
**normalización** para no repetir datos (sobre todo geolocalizaciones de los mapeos).

**Método:** 3 workstreams en paralelo (agentes) en áreas disjuntas; el orquestador coordinó
`routes/console.php`, `.env.example` y la verificación central.

## Verificación global (DoD, en containers)

- **Pint:** limpio (3104 archivos).
- **PHPStan nivel 8:** `No errors` (2682 archivos).
- **Pest:** suite completa **en verde** · **2096 passed / 5843 assertions** · **cobertura 81.9 %**
  (gate `--min=75`).
- Migraciones aditivas e idempotentes; `migrate` limpio (`CREATE INDEX IF NOT EXISTS`, `ALTER TABLE …
  SET`, `Schema::hasTable`). **Sin operaciones destructivas.**
- Sin `git commit`/`push`.

> **Nota sobre un fallo transitorio durante la verificación:** una corrida de cobertura marcó 1 fallo
> (`QueryException` en un test de render del outbox) que **no se reprodujo** en aislamiento, ni en su
> paquete, ni en una segunda corrida completa (2096 passed). Era un hipo transitorio de DB bajo la
> instrumentación pesada de pcov, no un defecto.

---

## 1) Salud física de las tablas giga-growers

- **Índices BRIN** (diminutos, ideales para append-only ordenado por tiempo) en
  `telemetry_pings(recorded_at)` y `execution_scan_events(occurred_at)`. Se **conservan** los btree
  existentes (acceso puntual/ORDER BY); el BRIN es complemento para barridos por rango amplio (pesa KB
  vs cientos de MB a escala). `telemetry_vehicle_positions` y `outbox_messages` **no** reciben BRIN
  (una fila por vehículo con UPDATE in-place el primero; índice parcial ya óptimo el segundo).
- **Autovacuum por tabla** (migraciones `ALTER TABLE … SET`): scale-factors agresivos en pings,
  positions, scan_events y outbox para que autovacuum no espere a millones de filas muertas; `fillfactor`
  ajustado donde hay UPDATE (favorece HOT updates).
- **Comando `logistics:db:maintain`** (`--analyze-only`, `--tables=`): `VACUUM (ANALYZE)` o `ANALYZE` de
  una allowlist de tablas calientes; nunca `VACUUM FULL`. **Programado** en `routes/console.php`:
  `dailyAt('03:30')` (pesado) + `hourly --analyze-only` (refresco de estadísticas), `onOneServer`.
- **Particionado:** diseño en [docs/perf/particionado-tablas-giga.md](particionado-tablas-giga.md).
  Particionado nativo por rango mensual sobre `telemetry_pings`/`execution_scan_events`; migración segura
  por `ATTACH PARTITION` (no recopia) + retención por `DROP PARTITION` (instantáneo, sin bloat). pg_partman
  no está en la imagen. **Recomendación: no convertir tablas con datos aún** (autovacuum+BRIN cubren el
  corto plazo); disparadores: ~50–100M filas, o cuando la retención exija DELETE masivos. Las tablas giga
  **nuevas** pueden nacer particionadas (aditivo, sin riesgo).

## 2) Retención / purga de logs (seguro, configurable, opt-in)

Motor reutilizable en `shared` (`PruneSpec`/`PruneReport`/`RowPruner` → `DbRowPruner`): borrado
**chunked** por `id`, cuenta antes de borrar, idempotente. Todos los comandos son **dry-run por
defecto** (solo borran con `--force`), con `--days`/`--chunk`/`--pause` y retención por env.

| Comando | Tabla | Qué poda | Retención default |
|---------|-------|----------|-------------------|
| `logistics:access:prune-audit-log` | `access_audit_log` | por `occurred_at` | 180 d |
| `logistics:outbox:prune` | `outbox_messages` | **solo** `dispatched`/`dead_lettered` (nunca pending/failed) | 14 d / 90 d |
| `logistics:telemetry:prune-pings` | `telemetry_pings` | raw GPS por `recorded_at` | 60 d |

**NO se podan** `telemetry_vehicle_positions` (proyección en vivo, 1 fila/vehículo), ni
`execution_scan_events`/deliveries/node_receptions/returns (registro de negocio). El motor permite
agregar retención futura por contexto si se justifica.

> **Importante — pendiente de tu decisión:** los crons de purga **NO** se programaron todavía. Son
> borrados reales (`--force`); el clasificador de seguridad los bloqueó para activación automática y,
> acorde a tu regla de "nada destructivo sin que lo pidas", quedan para que los habilites tú en
> `routes/console.php`. Las líneas sugeridas (02:30/02:45/03:00, `--force`, `withoutOverlapping`) están
> en el comentario de ese archivo. Primero conviene correr cada uno **sin `--force`** (dry-run) para ver
> cuántas filas tocaría.

## 3) Normalización / dedup de geolocalizaciones

- Tabla normalizada **`geocoding_cache`** (migración aditiva) + decorador `CachingGeocoder` del contrato
  `Geocoder`: antes de pegarle a Nominatim busca por hash de la dirección normalizada
  (trim/lowercase/colapso de espacios + país/proveedor); acierto → devuelve el `GeocodeResult` sin I/O e
  incrementa `hits`; fallo → resuelve y persiste (`insertOrIgnore`, seguro entre workers). Elimina el
  100 % de las llamadas HTTP repetidas a OSM por direcciones ya vistas (clientes recurrentes, reprocesos,
  backfills). `hits` mide la repetición real. Config: `SHIPMENTS_GEOCODER_PERSISTENT_CACHE` (default
  true); `NullGeocoder` no se cachea.
- Análisis en [docs/perf/normalizacion-dedup-datos.md](normalizacion-dedup-datos.md). Dedups ya
  resueltos (matrices OSRM por Valkey, street-stats por `segment_key`). Reverse-geocoding: **propuesta**
  (sin consumidor real hoy; cuando exista, en su contexto). Respetada la autonomía entre bounded contexts
  (no se normaliza cruzando contextos, acorde al diseño microservicios).

---

## Qué queda en tus manos

1. **Activar los crons de purga** (`--force`) en `routes/console.php` cuando quieras, tras revisar las
   ventanas de retención (en `.env.example`) y correr un dry-run. Recomendado: backup antes del primer
   `--force` en producción.
2. **Particionado**: ejecutarlo (con backup) cuando las tablas crezcan al umbral del doc.
3. Ajustar las ventanas de retención por env a la política real del negocio.

## Suma con la ola 1 (mismo día)

Esta ola (DB) se apoya en la anterior (app/runtime): caché Valkey de datos de referencia, read-side SQL
en shipments, índice parcial del outbox, FPM por env y Mailpit eliminado → sendmail nativo. Ver
[REPORTE-optimizacion-escala-2026-10-20.md](REPORTE-optimizacion-escala-2026-10-20.md).
