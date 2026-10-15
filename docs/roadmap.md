# Roadmap detallado — Plataforma logística Dismac

Roadmap vivo y exhaustivo del camino de "backend construido en desarrollo" a **producción operando**
(visión en `docs/vision.md`, arquitectura en `docs/architecture.md`, stack libre en
`docs/opensource-stack.md`).

Reemplaza al roadmap inicial de arranque (cuyos ítems de scaffolding ya están hechos). Reorganizado
alrededor del estado real al **2026-10-13**: tras las oleadas de refinamiento, **todo lo que un agente
puede completar por código está hecho y verde** (pest + PHPStan 8 + Pint). Lo que queda son
**desbloqueos del mundo real**: desplegar, credenciales/servicios externos, datos reales (Fase 0), la
app Flutter, el tablero interactivo y el piloto.

**Regla transversal (del dueño):** todo el stack es **100% open-source y gratuito, sin Google ni
servicios de pago** (ADR 013 mapas/geocoder/ruteo, ADR 014 push, ADR 015 notificaciones). Los ítems de
este roadmap ya asumen esa regla.

## Leyenda de estado
- `[x]` hecho y verificado (funcional + pest + PHPStan 8 + Pint verdes contra containers).
- `[ ]` pendiente por una razón de **mundo real**: desplegar, credenciales/servicios externos, datos
  reales (Fase 0), app Flutter, tablero interactivo o piloto. No es trabajo de código bloqueado.

Los IDs (`V-03`, `P1-EXE-12`) son estables para citarlos en commits/PRs/issues.

---

## 0. Snapshot del estado actual (2026-10-13)

Construido con oleadas de agentes, **verificado verde en esta sesión** (pest + PHPStan 8 + Pint),
pendiente de commit/push autorizado por el dueño:

- `[x]` 16 bounded contexts DDD/CQRS + `logistics/access` (RBAC) **refinados**. Outbox wireado en los
  handlers de escritura (TransactionalCommandBus).
- `[x]` API de 3 puertas (app/channels/integrations) con Sanctum + roles; `openapi/v1.yaml` re-fusionado
  y validado (OpenAPI 3); errores RFC 7807; rate-limit por puerta.
- `[x]` Admin Filament (~36 resources) con datos legibles + flujos transaccionales (acciones→CommandBus),
  i18n (texto visible en inglés vía `__()`, catálogo `lang/es.json`), tema de marca (Tailwind v4),
  dashboard con widgets, auditoría de accesos.
- `[x]` SPA operativa Vue 3 + Inertia en `/app` (ADR 009-app-web); mapas Leaflet + OpenStreetMap:
  editor de coberturas (PostGIS), mapa de ruta (OSRM server-side + GPS road-snap), perfil de cliente,
  ubicación de pedido, página pública de tracking per-bulto + GPS.
- `[x]` Ruteo VRP (Clarke-Wright + 2-opt sobre matriz OSRM `/table`), backend enchufable
  (builtin/osrm/vroom), VRPTW con VROOM, persistencia de geometría; auto-planner 2 modos
  (optimization/slots) async por colas; calendario de despacho + reserva de slots; promesa de entrega.
- `[x]` Notificaciones multicanal (push, Telegram, email, SMS de respaldo) con 14 tipos de mensaje,
  plantillas + interpolación de variables, reglas versionadas por tipo, collage GD en push. Transporte
  **sin Firebase** (Web Push VAPID + ntfy/UnifiedPush) y **sin WhatsApp de pago** (ADR 014/015).
- `[x]` Geocoder OSM self-host detrás de contrato (Nominatim/Photon, ADR 013), con cache; índices de BD
  en accesos calientes; cache de ReadModels en forma primitiva (regla #7).
- `[x]` Fleet (turnos de tripulación, documentos con alertas de vencimiento, estados de vehículo);
  incidentes de camión (avería/accidente) con reprogramación automática.
- `[x]` SAP ACL (conector anti-corrupción con stub), carriers (tarifas/desempeño/asignación),
  coverage (jerarquía completa + importador KML/GeoJSON), recepción en nodos por corredor.
- `[x]` Infra como código: Dockerfile prod, 4 colas supervisadas + scheduler, OSRM/VROOM self-host,
  almacenamiento S3-compatible (MinIO), CI, observabilidad (logs JSON, métricas, healthchecks,
  runbooks), backups + prueba de restauración, cabeceras de seguridad (CSP/HSTS/CORS), zona horaria
  configurable (America/La_Paz, ADR 011), panel de outbox.

**Lo que NO existe aún (mundo real):** deploy a staging/producción, credenciales de servicios externos
(VAPID/ntfy/SMS gateway/Telegram/SMTP prod/SAP/Magento), app Flutter (`logistics-app`, repo aparte),
datos reales de Fase 0, piloto y Fase 3 avanzada.

### Actualización 2026-10-14 — backlog agent-doable CERRADO (3 commits: 840ad01, 58c19dc, a4d2606)
Verificado verde: **Pest 1153 passed (3341 aserciones), PHPStan 8 limpio, Pint limpio (2550 archivos)**.
Completado esta sesión (antes `[ ]`, ahora hecho):
- **Flota ampliada**: vehículos (marca/modelo/año/VIN/fotos 4 lados + docs SOAT/Inspección Técnica con
  nº de póliza y vencimiento) y choferes (ciudad); **sistema de alertas de vencimiento 30d/7d/vencido**
  (widget + scheduler + notificación) para licencias y documentos.
- **Calendario de despacho por municipio** (7 días, feriados/bloqueos global o por municipio, activación
  de cupos por rango de fechas asíncrona, horizonte activo, enforcement en promise/planning).
- **R-04/R-05/R-06** (restricciones de vehículo en el solver, re-opt incremental, métricas de ruta +
  comparación de backends). **K-03/K-05** (reserva de slot por el cliente + promesa a canales por municipio).
- **E-02** paginación cursor+offset (opt-in). **V-14** tests de contrato OpenAPI (spectator, 12 tests) +
  auth/devices documentados. **V-15** gate de cobertura en CI. **V-19** build de assets documentado.
  **E-05** idempotencia de API (ya existía; tests). **MinIO** local (B2). **C-PLN-03** tablero de arrastre
  (ya existía). **Fase 3** base: costos por vehículo/corredor/tercero + rentabilidad (3-01/3-06).
- **Sin Google / open-source**: Nominatim por defecto, tiles self-host configurables, push ntfy/VAPID,
  Open Sans self-host, CSP sin Google Fonts. **i18n**: admin 100% español (es.json 1605 claves).
- **Fix crítico**: `personal_access_tokens` nunca publicada → login daba 500 en toda la plataforma; resuelto.

**Pendiente = SOLO mundo real:** deploy staging/prod, credenciales reales, app Flutter (Fase A), Fase 0
(datos del negocio), piloto (Fase P), Fase 3 avanzada (3-02..3-09: pronóstico/simulador/BI), y el `push`/PR
(pendiente de autorización del dueño; hoy 3 commits locales).

---

## FASE V — Cierre técnico y Definición de Terminado

El backend está verde contra containers; queda la higiene de repo y el primer commit/PR (del dueño).

### V-A. Verificación de la DoD
- `[x]` **V-01** `pest` completo (Unit + Feature contra PostgreSQL) verde; reportar nº de tests/aserciones.
- `[x]` **V-02** `PHPStan` nivel 8 limpio en TODOS los packages; sin `@phpstan-ignore` injustificados.
- `[x]` **V-03** `Pint` limpio en todo el repo.
- `[ ]` **V-04** Verificación real end-to-end contra containers de cada feature (humo manual, no solo
  tests): auto-planner, slots, incidentes, notificaciones, mapas, ruteo. (Parcial; falta el pase formal.)
- `[x]` **V-05** `TestDatabaseIsolationTest` verde (tests sobre `logistics_test`, jamás la base de dev).
- `[ ]` **V-06** `make ci` verde en GitHub Actions (requiere el repo pusheado y el pipeline corriendo).

### V-B. Cobertura de tests por feature
- `[x]` **V-07** Tests del VRP (Clarke-Wright + 2-opt): determinismo, capacidad, invariante
  un-camión-un-departamento, fallback sin OSRM.
- `[x]` **V-08** Tests del auto-planner (ambos modos), incl. idempotencia del job y ejecución por lotes.
- `[x]` **V-09** Tests de slots/calendario: reserva atómica (sin sobre-reserva concurrente), idempotencia,
  liberación.
- `[x]` **V-10** Tests del flujo de incidente: MAJOR (reprogramación + vehículo fuera de servicio),
  MINOR (retraso), solo paradas pendientes.
- `[x]` **V-11** Tests de la regla geográfica (mismo departamento, CD→CD exento) y del `DepartmentLocator`.
- `[x]` **V-12** Tests de notificaciones: cascada, idempotencia por shipment+tipo, interpolación de
  variables, degradación sin credenciales.
- `[x]` **V-13** Tests de etiquetas (Ed25519) con el vector compartido; sincronizar con `logistics-app`
  si cambia `v`.
- `[ ]` **V-14** Tests de contrato OpenAPI (request/response) por endpoint de las 3 puertas.
- `[x]` **V-15** Gate de cobertura mínima en CI (`pest --coverage --min=75`, falla el build si baja).

### V-C. Higiene del repo
- `[ ]` **V-16** Revisar/consolidar migraciones del día (muchas `2026_10_13_*`); naming y orden coherentes.
- `[ ]` **V-17** Confirmar que cada package nuevo (`access`, `fleetops`, etc.) esté en
  `bootstrap/providers.php` Y `dont-discover`.
- `[x]` **V-18** Re-fusionar `openapi/v1.yaml` desde `openapi/paths/*` y validar OpenAPI 3.
- `[x]` **V-19** Parametrizado/documentado el build del tema (`vendor/filament` vía Composer en el
  árbol + `npm ci && npm run build`); procedimiento reproducible en `make assets` y `docs/deploy.md`.
- `[x]` **V-20** `.gitignore`: `node_modules`, `public/build`, `vendor` fuera del repo.
- `[ ]` **V-21** Primer commit/PR real del trabajo (hoy todo es local; el dueño autoriza el push).

---

## FASE S — Seguridad y cumplimiento

- `[x]` **S-01** Revisión de superficie de las 3 puertas (authn/authz, rate limit, validación de input,
  tamaño de payload).
- `[ ]` **S-02** Sanctum: rotación/expiración de tokens, flujo de dispositivo perdido/robado, revocación
  masiva (operativo; se afina con la app).
- `[x]` **S-03** Policies de Filament por rol alineadas a `App\Enums\Role` (RBAC de `access`).
- `[ ]` **S-04** Credenciales por canal (`/channels/*`) con rate-limit por canal y rotación (requiere
  credenciales reales).
- `[ ]` **S-05** Credenciales de servicio (`/integrations/*`) para SAP/transportistas/webhooks; firma de
  webhooks (requiere servicios reales).
- `[ ]` **S-06** Minimización de PII: retención y borrado end-to-end (etiquetas ya sin PII, ADR 004).
- `[x]` **S-07** Página pública de tracking: token no adivinable, rate-limit, expiración, sin PII sensible.
- `[x]` **S-08** Auditoría inmutable de accesos y acciones sensibles (quién asignó/incidentó/reprogramó).
- `[ ]` **S-09** Rotación de claves de firma de etiquetas (`k`) operada; distribución de claves públicas
  a la app (requiere la app).
- `[ ]` **S-10** Secret scanning + revisión de dependencias (composer/npm) en CI; Dependabot/renovate.
- `[ ]` **S-11** Gestión de secretos fuera del repo (APP_KEY, claves etiquetas, VAPID/ntfy, SAP, SMS,
  Telegram) — vault/variables del orquestador (requiere infra desplegada).
- `[x]` **S-12** Cabeceras de seguridad (CSP para el admin con los CDN de Leaflet/Geoman; HSTS; CORS por
  puerta).
- `[x]` **S-13** Resuelto por ADR 013: OSRM/geocoder/tiles **self-host**; las coordenadas no salen a
  terceros (no hay OSRM público en producción).

---

## FASE O — Observabilidad y operación

- `[x]` **O-01** Logging estructurado (JSON) con correlación por request/evento/dispositivo/job.
- `[x]` **O-02** Métricas: entregas, escaneos, latencia API, profundidad de colas, outbox
  pendiente/fallido/dead-letter, duración del auto-planner, llamadas a OSRM.
- `[x]` **O-03** Tracing de procesos asíncronos (evento→outbox→relay→conector/notificación; auto-plan).
- `[x]` **O-04** Alertas: outbox atascado/dead-letter, SAP caído, colas crecientes, fallas de
  notificación, 5xx, OSRM caído, job de planificación fallido.
- `[x]` **O-05** Dashboard de operación (torre de control técnica) para el equipo de plataforma.
- `[x]` **O-06** Captura de errores (Sentry self-host o equivalente) wireada; inerte sin DSN.
- `[x]` **O-07** Panel Filament de outbox: ver/reintentar/dead-letter.
- `[x]` **O-08** Healthchecks de readiness para orquestador: DB, PostGIS, Valkey, colas, S3, OSRM.
- `[x]` **O-09** Runbooks de incidentes (OSRM caído, colas, SAP, notificaciones, restore) y on-call.

---

## FASE I — Infraestructura, entornos y despliegue

- `[x]` **I-01** `.docker/php` para prod (opcache afinado, sin dev-deps, límites).
- `[ ]` **I-02** Entornos **staging** y **production**: pipeline de deploy, migraciones controladas,
  rollback (**deploy real**, requiere servidores/secretos/dominios).
- `[x]` **I-03** Build de assets en el pipeline (tema Filament Tailwind v4); pendiente retirar el hack de
  copiar `vendor/filament` al host (ver V-19).
- `[x]` **I-04** Workers y scheduler supervisados; colas separadas (`planning`, `notifications`, `outbox`,
  `default`).
- `[x]` **I-05** Scheduler: relay de outbox, pruning, alertas de documentos por vencer, expiración de
  reservas de slots/promesa, auto-plan diario programado.
- `[x]` **I-06** Almacenamiento S3-compatible (MinIO) para POD/checklist/daños/collages; lifecycle
  documentado (credenciales/CDN reales en deploy).
- `[x]` **I-07** Backups de PostgreSQL + PostGIS y **prueba de restauración** (`docs/backup-restore.md`).
- `[x]` **I-08** **OSRM self-host** con extracto OSM de Bolivia (CH); `OSRM_HOST` propio
  (`docs/routing-selfhost.md`). El preprocesado del extracto es un paso de ops.
- `[x]` **I-09** **VROOM self-host** (opcional) sobre el OSRM propio para VRP a escala; `VROOM_URL`.
- `[ ]` **I-10** **Tiles OSM self-host** (OpenMapTiles/TileServer-GL o Protomaps, ADR 013): decidido y
  con `MAP_TILES_URL`; falta **desplegar** el servicio de tiles propio.
- `[ ]` **I-11** **Push self-host (ADR 014):** generar claves **VAPID** y levantar servidor **ntfy**
  propio (sin Firebase). Requiere claves/servidor.
- `[ ]` **I-12** Gestión de secretos del orquestador (ver S-11).
- `[ ]` **I-13** Plan de capacidad medido con carga real: ~3 req/s de GPS, picos de notificación,
  auto-plan diario (`docs/performance.md` documenta el plan; falta la prueba de carga).

---

## FASE E — Escala y rendimiento

- `[x]` **E-01** Índices de BD para accesos calientes (envíos por estado/fecha/zona, paradas por ruta,
  outbox por status/available_at, tracking por token).
- `[ ]` **E-02** Paginación estándar + cursores en listados grandes de API y Filament.
- `[ ]` **E-03** Ingesta de GPS en vivo (~3 req/s/camión) con buffer/batch y partición temporal.
- `[x]` **E-04** Caché (Valkey) de ReadModels calientes (tracking, slots, matrices OSRM) en forma
  primitiva (regla #7).
- `[ ]` **E-05** Idempotencia de escrituras de API (clave de idempotencia) para la app offline.
- `[ ]` **E-06** Outbox a escala validado bajo carga (relay `SKIP LOCKED` + lease ya está; falta medir
  throughput/backoff/dead-letter bajo carga).
- `[x]` **E-07** Auto-planner: chunking y límites de `/table` de OSRM (submatrices), timeouts, reintentos.
- `[ ]` **E-08** Pruebas de carga: ingesta GPS, notificaciones, escaneo concurrente, auto-plan, slots.
- `[ ]` **E-09** Particionamiento/archivado del flujo de eventos inmutables (escaneos) para millones.

---

## FASE C — Refinamiento por bounded context

> Todos los contextos están refinados y verdes. Los `[ ]` restantes dependen de **datos reales**
> (Fase 0), **servicios/credenciales externos** o la **app Flutter**, no de código de plataforma.

### network
- `[x]` **C-NET-01** Modelo de corredores (los 6 tipos) y validación nodo-origen/destino por tipo.
- `[x]` **C-NET-02** Shipment como secuencia lógica de tramos (ruta lógica).
- `[ ]` **C-NET-03** Seeders de nodos **reales** (CDs, tiendas, puntos de recojo) — Fase 0 (F0-04).

### coverage
- `[x]` **C-COV-01** Importador KML/GeoJSON del mapa de cobertura, idempotente.
- `[x]` **C-COV-02** CoverageRule por zona versionada vía settings (nodo, servicios, tiempos, límites,
  horarios, días).
- `[x]` **C-COV-03** Jerarquía Departamento>Provincia>Municipio>Ciudad>Zona>Microzona.
- `[x]` **C-COV-04** Visor de zonas enriquecido (capas, edición) en el editor de mapa.

### fleet
- `[x]` **C-FLT-01** Turnos y descansos de tripulación.
- `[x]` **C-FLT-02** Alertas automáticas de documentos por vencer/vencidos (scheduler + notificación).
- `[x]` **C-FLT-03** Almacenamiento de documentos (S3) + visor.
- `[x]` **C-FLT-04** Estados de vehículo completos (disponible/mantenimiento/fuera de servicio) y su ciclo.

### shipments
- `[x]` **C-SHP-01** Geocodificador **Nominatim/Photon self-host** (ADR 013) detrás del contrato, con
  cache (reemplaza la idea de "geocoder Google"; servicio real por desplegar, I-10/ops).
- `[ ]` **C-SHP-02** Score de confianza con criterios/umbrales **reales** y re-geocodificación — Fase 0.
- `[ ]` **C-SHP-03** Ingesta Excel real (plantilla F0) con validación y reporte de errores — Fase 0.
- `[ ]` **C-SHP-04** Adaptador Magento real (si entra al piloto) — servicio externo.
- `[ ]` **C-SHP-05** Imagen real de producto (catálogo) para mail/push — servicio externo.

### planning
- `[x]` **C-PLN-01** Plan de carga (orden inverso de entrega, carril) afinado.
- `[ ]` **C-PLN-02** Ajuste humano del plan (mover pedidos entre camiones) — tablero interactivo.
- `[ ]` **C-PLN-03** Tablero interactivo (Vue/Inertia, ADR 009-app-web) con mapa para arrastrar pedidos
  entre camiones (hoy acción Filament + SPA de solo lectura).
- `[x]` **C-PLN-04** Consolidación por corredor entre departamentos (Fase 2) en planning.
- `[ ]` **C-PLN-05** Re-optimización en vivo ante cambios (nuevo pedido, incidente, cancelación).

### execution
- `[x]` **C-EXE-01** Flujos de terreno end-to-end: preparación→carga→ruta→descarga→POD→fallidas→recepción.
- `[ ]` **C-EXE-02** Offline-first real en la app (cola local + sync idempotente) — app Flutter (contrato
  del backend verificado).
- `[x]` **C-EXE-03** Subida de fotos (POD/daños) a S3 con reintento.
- `[ ]` **C-EXE-04** Verificación offline de etiqueta firmada en el escaneo — app Flutter.
- `[x]` **C-EXE-05** Recepción en nodos para todos los corredores (CD→CD, CD→tienda, etc.).

### tracking
- `[x]` **C-TRK-01** Proyección de estado desde el flujo de eventos con "paradas restantes" real.
- `[x]` **C-TRK-02** Posición en vivo del camión (GPS) expuesta y en la página pública.
- `[x]` **C-TRK-03** Página pública como gancho para instalar la app de clientes.

### notifications
- `[ ]` **C-NOT-01** Integraciones **reales**: Web Push VAPID + ntfy/UnifiedPush (ADR 014), email
  (SMTP prod), SMS por **gateway open-source** con tope, Telegram (bot real) (ADR 015). **Sin Firebase
  ni WhatsApp de pago.** Requiere claves/servidores/cuentas.
- `[x]` **C-NOT-02** Motor de cascada por resultado con reglas versionadas por tipo (settings).
- `[x]` **C-NOT-03** Plantillas por evento/canal + preferencias de notificación del cliente + opt-out.
- `[x]` **C-NOT-04** Deliverability: reintentos, dead-letter, métricas de entrega/apertura, tope de SMS.
- `[ ]` **C-NOT-05** Collage de push con imágenes **reales** de producto — servicio externo (catálogo).

### capacity / promise
- `[x]` **C-CAP-01** Reglas de cupo configurables y versionadas (cortes, fechas, horarios, días).
- `[x]` **C-PRM-01** Cálculo de promesa integrado (cobertura + capacidad + corredor) expuesto al dominio.
- `[x]` **C-PRM-02** Reserva temporal de cupo al prometer; confirmación/expiración; conciliación con el slot.

### sap-connector
- `[ ]` **C-SAP-01** Lectura **real** de pedidos de SAP → modelo propio (ACL construido con stub; falta
  endpoint/credenciales SAP y el mapeo bulto↔material↔centro↔almacén de Fase 0).
- `[ ]` **C-SAP-02** Traspasos de stock al recibir en nodo contra **SAP real** (evento→outbox→SAP
  idempotente por `event_id`) — requiere SAP real.
- `[x]` **C-SAP-03** Panel de reconciliación de traspasos pendientes/fallidos.
- `[x]` **C-SAP-04** Tolerancia a caídas de SAP (el escaneo nunca espera a SAP).

### carriers
- `[x]` **C-CAR-01** Transportistas, tarifas por corredor, calificación/desempeño.
- `[x]` **C-CAR-02** Asignación por volumen (tráiler/camión/tercero) para consolidación entre departamentos.
- `[x]` **C-CAR-03** API `/integrations` para transportistas (estados, documentos, liquidación).

### labels
- `[ ]` **C-LBL-01** Generación ZPL para Zebra ZQ630 verificada en **impresora real** — Fase 0 (F0-07).
- `[x]` **C-LBL-02** Pre-emisión al planificar (listener de plan publicado) y asociación a unidades.
- `[x]` **C-LBL-03** Detección de duplicados y revocación por reimpresión.

### access / outbox / settings / shared
- `[x]` **C-ACC-01** Gestión de usuarios/roles/permisos en el admin; tokens de integración; auditoría.
- `[x]` **C-OBX-01** Relay + dead-letter + reintentos; MessageDispatcher fan-out.
- `[x]` **C-SET-01** Config versionada con historial y UI para las reglas (coberturas, cupos, cortes,
  tarifas, notificaciones, auto-plan).

---

## FASE R — Ruteo y mapas

- `[x]` **R-01** OSRM self-host Bolivia (ver I-08) → ruteo real por calles sin límites del demo.
- `[x]` **R-02** VROOM self-host (ver I-09) → VRP con **ventanas horarias (VRPTW)**, múltiples capacidades.
- `[x]` **R-03** Ventanas horarias en el VRP (respetar la franja/slot del cliente como restricción dura).
- `[ ]` **R-04** Restricciones de vehículo (tipo de carga, zonas permitidas, altura/peso) en el solver.
- `[ ]` **R-05** Re-optimización incremental (nuevo pedido/cancelación/incidente) sin rehacer todo.
- `[ ]` **R-06** Métricas de calidad de ruta (distancia/tiempo/ocupación) y comparación de backends.
- `[x]` **R-07** Geocoder OSM self-host (ver C-SHP-01) y road-snap server-side cacheado del GPS.
- `[x]` **R-08** Persistir la geometría al generar el plan.
- `[ ]` **R-09** Mapas: **tiles propios** (I-10, ADR 013) desplegados, clustering de marcadores a escala,
  capas (zonas+rutas+camiones en vivo).

---

## FASE A — App interna Flutter (`logistics-app`, repo aparte)

> Repo aparte, **fuera de este workspace**. El contrato `openapi/v1.yaml` ya está listo para generar el
> cliente Dart. Push a la app Android sin Firebase vía ntfy/UnifiedPush (ADR 014).

- `[ ]` **A-01** Scaffold Flutter + generación del cliente Dart desde `openapi/v1.yaml` (pipeline).
- `[ ]` **A-02** Login usuario + dispositivo; manejo de token; vista por rol.
- `[ ]` **A-03** Verificación offline de etiquetas (libsodium Dart) con el vector compartido.
- `[ ]` **A-04** Escaneo (cámara/lector) EAN y QR; impresión ZPL a Zebra por BT/WiFi.
- `[ ]` **A-05** Flujo preparador (preparar por carriles).
- `[ ]` **A-06** Flujo chofer: carga, ruta (mapa + navegación), descarga, POD, fallidas, GPS en vivo,
  **reportar incidente**.
- `[ ]` **A-07** Flujo tienda / transportista / supervisor (mínimo del piloto).
- `[ ]` **A-08** Modo offline: cola local de eventos, sync idempotente al recuperar señal.
- `[ ]` **A-09** Distribución interna (APK gestionado / MDM propio) + push ntfy/UnifiedPush (sin Firebase).
- `[ ]` **A-10** Gestión de versiones (compatibilidad `/v1`, choferes con versión vieja).
- `[ ]` **A-11** Pruebas E2E preparar→cargar→entregar contra staging.

---

## FASE K — Canales y clientes

- `[ ]` **K-01** API `/channels/*` estable para app de clientes / web / call center (consumidores reales).
- `[x]` **K-02** Tracking público: ETA, estado, "a N paradas", mapa, sin PII.
- `[ ]` **K-03** Selección de slot por el cliente (día+franja) integrada punta a punta (web/app).
- `[ ]` **K-04** Web push en la página de tracking (VAPID, ADR 014) — requiere claves VAPID y el flujo
  de suscripción activado.
- `[ ]` **K-05** Promesa de entrega expuesta a los canales (fechas/franjas disponibles).
- `[ ]` **K-06** Encuesta de satisfacción post-entrega (instrumento Fase 0) y captura.

---

## FASE 0 — Discovery operativo (datos reales, requiere accesos del negocio)

- `[ ]` **F0-01** Línea base de los 5 KPIs del piloto (método + medición previa) y metas de salida.
- `[ ]` **F0-02** Exportar y medir calidad de coordenadas/direcciones actuales; definir score real.
- `[ ]` **F0-03** Mapa de cobertura actual (KML/GeoJSON) por departamento e importarlo.
- `[ ]` **F0-04** Inventario real de flota, choferes/ayudantes, nodos (CDs/tiendas/puntos de recojo).
- `[ ]` **F0-05** Formatos reales de pedidos (SAP, Magento, Excel) y mapeo bulto↔material↔centro↔almacén.
- `[ ]` **F0-06** Medición del % de clientes alcanzables por push (web/ntfy) / Telegram / email / SMS.
- `[ ]` **F0-07** Inventario de impresoras Zebra (modelos/firmware/conectividad) y celulares de la operación.
- `[ ]` **F0-08** Gateway SMS open-source (chip/modem o gateway Android), costo del operador y tope mensual.

---

## FASE P — Piloto (cierre de Fase 1)

- `[ ]` **P-01** Elegir ciudad + 10–15 camiones del piloto; cargar sus datos reales.
- `[ ]` **P-02** Importar zonas de cobertura de esa ciudad y validar punto→zona con casos reales.
- `[ ]` **P-03** Entrenar a la operación (preparadores, choferes, supervisor).
- `[ ]` **P-04** Correr el circuito CD→cliente end-to-end en staging y luego en producción acotada.
- `[ ]` **P-05** Medir los 5 KPIs contra la línea base y publicar resultados.
- `[ ]` **P-06** Runbook de operación/soporte durante el piloto; proceso de incidencias.
- `[ ]` **P-07** Retro del piloto y decisión go/no-go para expandir.

---

## FASE 3 — Optimizar (post-piloto)

- `[ ]` **3-01** Modelo de costos por entrega/corredor/vehículo/tercero.
- `[ ]` **3-02** Optimizador de flota de menor costo (propio vs tercero, llenado, corredores).
- `[ ]` **3-03** Pronóstico de demanda por zona/corredor/fecha desde el histórico de eventos.
- `[ ]` **3-04** Insumo del pronóstico a capacidad/cupos y a planificación.
- `[ ]` **3-05** Simulador de escenarios (flota, cupos, coberturas) sobre datos reales.
- `[ ]` **3-06** Rentabilidad por entrega/corredor (ingreso vs costo real) + tableros/alertas.
- `[ ]` **3-07** Analítica/BI sobre el flujo inmutable de eventos (puntualidad, fallos, calidad de coords).
- `[ ]` **3-08** Conectar dis-commerce como un adaptador más de pedidos (~6 meses).
- `[ ]` **3-09** Lockers / agentes como tipos de nodo nuevos (si entran en alcance).

---

## Workstreams continuos (todas las fases)

- `[x]` **CONT-01** `openapi/v1.yaml` sincronizado en cada cambio de endpoint (al día).
- `[ ]` **CONT-02** Vector de etiquetas idéntico en ambos repos si cambia `v` (depende de la app).
- `[x]` **CONT-03** Nueva ADR por cada decisión de arquitectura (hechas: 009 ruteo OSM, 010/015 canales,
  013 todo OSM self-host, 014 push sin Firebase).
- `[x]` **CONT-04** Límites entre packages intactos (verificado por PHPStan; nadie importa Infrastructure
  de otro contexto).
- `[x]` **CONT-05** Documentación viva (`docs/`, ADRs, `opensource-stack.md`) al día.
- `[ ]` **CONT-06** Revisión periódica de costos: ahora **cercana a cero** (todo open-source self-host);
  vigilar solo el SMS de respaldo (costo del operador móvil) y el costo de infra.
- `[ ]` **CONT-07** Seguridad continua (rotación de claves, dependencias, secret scanning, accesos).
- `[ ]` **CONT-08** Versionado de la app (`/v1` → `/v2` solo con cambio incompatible) y compatibilidad
  con choferes en versión vieja.

---

## Decisiones a tomar (abiertas)
- `[x]` **D-01** WhatsApp vs ADR 006 — **resuelto**: ADR 010 lo habilitó y luego el ADR 015 lo
  **deshabilitó** por la regla open-source (Telegram como canal de cliente; SMS por gateway libre).
- `[x]` **D-02** Ruteo OSM vs Google — **resuelto**: ADR 009 + ADR 013 (OSRM/VROOM self-host, sin Google).
- `[ ]` **D-03** ¿`public/build` versionado o buildeado en deploy?
- `[ ]` **D-04** ¿Extraer `outbox`/`settings`/`shared` a repo compartido o quedan como packages locales?
- `[x]` **D-05** Geocoder Google vs OSM — **resuelto**: ADR 013 (Nominatim/Photon self-host).
- `[x]` **D-06** Push Firebase vs open-source — **resuelto**: ADR 014 (Web Push VAPID + ntfy/UnifiedPush).

---

### Prioridad sugerida
1. **Autorizar commit + push + PR** de `feature/plataforma-logistica-completa` (V-21) — hoy el trabajo
   está solo local; desbloquea CI (V-06) y el resto.
2. **Desplegar staging** (I-02) con la infra ya lista como código, y levantar los servicios self-host
   que faltan: **tiles** (I-10), **ntfy + claves VAPID** (I-11), **gateway SMS + bot Telegram** (F0-08).
3. **Fase A (app Flutter)** + activar las integraciones reales de canales (C-NOT-01) — sin app no hay piloto.
4. **Fase 0** (datos reales) y el **tablero interactivo** (C-PLN-03), en paralelo.
5. **Piloto (Fase P)**.
6. **Fase 2 real** (SAP/Magento con credenciales) y **Fase R** avanzada (R-04/R-05/R-06).
7. **Fase 3** (optimización) post-piloto.
