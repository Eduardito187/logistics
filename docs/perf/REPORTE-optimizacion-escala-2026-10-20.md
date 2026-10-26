# Reporte — Optimización de performance a gran escala (2026-10-20)

**Objetivo:** soportar miles de usuarios concurrentes, servicios ultra rápidos, caché de datos
repetidos y procesos transaccionales en simultáneo. Además: **eliminar Mailpit al 100%** (el email
pasa a la función nativa del host, sin servicio de correo propio).

**Método:** 4 workstreams en paralelo (agentes), particionados por contexto/carpeta para no pisarse;
coordinación central de archivos compartidos (`.env.example`, `docker-compose.yml`, `config/` raíz) y
verificación central serializada.

## Verificación global (DoD, en containers)

- **Pint:** limpio (3077 archivos).
- **PHPStan nivel 8:** `No errors` (2664 archivos).
- **Pest:** suite completa **en verde** (exit 0; Pest falla si hay cualquier test rojo o cobertura
  bajo el gate) · **cobertura 81.8 %** (gate `--min=75` superado) · ~2072 tests.
- **Migraciones:** todas aditivas e idempotentes; `migrate` aplicado limpio. Sin operaciones
  destructivas.
- **Sin** `git commit`/`push` (lo hace el usuario).

---

## 0) Mailpit eliminado al 100%

- `docker-compose.yml`: servicio `mailpit` y volumen `mailpit_data` eliminados; `docker compose config`
  valida OK.
- `.env.example`: `MAIL_MAILER=sendmail` + `MAIL_SENDMAIL_PATH="/usr/sbin/sendmail -bs -i"` (binario
  sendmail nativo del hosting; sin SMTP ni catcher). Se eliminaron `MAIL_HOST/PORT/SCHEME` de Mailpit.
- README, Makefile, `docs/opensource-stack.md`, ADR 015, config y comentarios de `notifications`
  actualizados. **Cero** referencias a mailpit en el árbol (verificado con grep).
- El `EmailChannelSender` sigue igual: usa el mailer de Laravel, que ahora resuelve a sendmail del host.

---

## 1) Read-side CQRS en `shipments` (fin de la carga en memoria)

- **Antes:** `ListShipments` / `ListLowConfidenceAddresses` llamaban `ShipmentRepository::all()`, que
  cargaba TODA la tabla, reconstruía el agregado por fila y filtraba en PHP. Inviable a millones.
- **Ahora:** un ReadModel SQL dedicado (`ShipmentListReader` → `DbShipmentListReader`) empuja los
  filtros (`status`, `source`, `destination_node_id`, low-confidence) a `WHERE` indexable y arma el
  `ShipmentView` sin reconstruir el agregado (2 consultas acotadas: cabeceras + líneas por `whereIn`,
  sin N+1). **Contrato idéntico** (test de paridad `toArray` == camino histórico); el scope por rol
  Tienda y la paginación opcional no se tocaron.
- Índices nuevos en `shipments`: `(destination_node_id, status)` y `(address_confidence_level)`.
- **Fix de robustez (hallado en verificación):** la relación `ShipmentRecord::lines()`/`units()` cargaba
  en orden de heap (no determinista bajo churn), lo que divergía del orden por `id` del lector. Se fijó
  `->orderBy('id')` en ambas relaciones: rehidratación determinista y paridad estable. Era un
  nondeterminismo latente del camino legacy, no solo del test.

## 2) Caché de datos repetidos (Valkey, con invalidación por escritura)

Patrón replicado del ya probado en `promise`/`capacity` (decorador que guarda **siempre la forma
primitiva** —array— y rehidrata; regla #7). Invalida al instante en `save()`/`remove()`; el TTL es solo
la red de seguridad. Keys con prefijo por contexto.

| Contexto | Lectura caliente cacheada | TTL |
|----------|---------------------------|-----|
| network | `Node::all`, `Leg::all` | 3600 s |
| coverage | `Zone::all`, `GeoArea::all`, `Municipality::all` (CTE recursivo caro) | 3600 s |
| notifications | `MessageTemplate::all`, `MessageTypeRule::all` | 600 s |
| sap-connector | `MaterialMapping::all` | 3600 s |
| settings | `GetSetting` "a ahora" (reglas de zona, cascadas, overrides) | 600 s |

No se cachean lecturas por-usuario ni la resolución espacial PostGIS ni el render por-notificación
(consistencia). 29 tests nuevos (cache hit, forma primitiva, invalidación).

## 3) Índices y concurrencia transaccional (outbox)

5 migraciones aditivas idempotentes (`CREATE INDEX IF NOT EXISTS`, guard pgsql).

- **Outbox (lo más importante):** índice **parcial** `(available_at) WHERE status IN ('pending',
  'failed')` que respalda exactamente la consulta del relay (`FOR UPDATE SKIP LOCKED ... ORDER BY
  available_at LIMIT n`). Verificado con `EXPLAIN` sobre backlog simulado (700k filas, en transacción
  con ROLLBACK): el planner usa el índice **sin nodo Sort**, el `LIMIT` corta tras N lecturas
  ordenadas. Habilita más workers en paralelo sin contención.
- **execution** (deliveries por `created_at`; scans por `delivery_id,type,occurred_at`), **telemetry**
  (trips y alerts por vehículo+tiempo; alertas sin reconocer parcial), **carriers** (tarifas por
  corredor+clase+precio; settlements por período), **fleetops** (precios de combustible, recargas,
  mantenimientos, gastos por vehículo+fecha).
- Contextos sin cambios por estar ya cubiertos (planning, capacity, promise, tracking): documentado
  para no duplicar índices "por si acaso".

## 4) Runtime del servidor de aplicación

- **PHP-FPM** parametrizable por env (`FPM_*` en `.env.example`, defaults horneados dev-safe):
  `start_servers` 5→8, `min_spare` 5→8, `max_spare` 10→16, `max_requests` 500→1000; `max_children` 50
  con fórmula documentada `≈ (RAM_util*0.75)/~96MB`. El entrypoint renderiza el pool desde plantilla
  (php-fpm no interpola env en directivas numéricas — verificado con `php-fpm -t`).
- **OPcache/JIT** ya estaban óptimos en la imagen prod (`validate_timestamps=0`, memory 512,
  interned 32, max_accelerated_files 30000, JIT tracing 1255/128M) — no se tocaron.
- **Arranque prod:** `config:cache` + `route:cache` + `view:cache` + `event:cache` + `filament:optimize`
  (bajo `OPTIMIZE_ON_BOOT`, solo prod; dev no cachea).
- **Preload:** `bootstrap/preload.php` preparado como **opt-in desactivado** (requiere auditar los
  service providers de los packages y medir con load test antes de activar). Documentado.
- **Health:** `Cache-Control: no-store` para que ningún proxy sirva un "ok" viejo.
- **Octane/FrankenPHP:** evaluación en [docs/perf/octane-frankenphp-evaluacion.md](octane-frankenphp-evaluacion.md).
  **Recomendación: no adoptar ahora** (FPM+OPcache/JIT ya afinado, aislamiento de estado por request,
  riesgo mínimo); promover a spike solo cuando un load test demuestre que el bootstrap del framework
  —no la DB— es el cuello p95/p99; si se hace, preferir FrankenPHP worker mode.

---

## Pendientes documentados (no forzados)

- **Preload** y **JIT buffer**: medir con load test (Fase 4) antes de activar preload.
- **telemetry_pings**: candidata a BRIN sobre `recorded_at` (append-only de millones); hoy la sirve un
  btree. Cambiar btree→BRIN es rediseño (no aditivo), fuera de alcance.
- **notifications**: `findFor`/`policyFor` (render por notificación) no cacheado a propósito; posible
  mejora futura si se acepta el trade-off de consistencia.
- **docs/deploy.md**: conviene listar `filament:optimize` y las `FPM_*`.
- Próximo paso natural: **load test** para fijar `FPM_MAX_CHILDREN` real por tamaño de contenedor y
  decidir preload/Octane con datos.
