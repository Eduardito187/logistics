# Roadmap detallado — Plataforma logística Dismac

Roadmap vivo y exhaustivo del trabajo **restante** para llevar la plataforma de "backend construido en desarrollo"
a **producción operando** (visión en `docs/vision.md`, arquitectura en `docs/architecture.md`).

Reemplaza al roadmap inicial de arranque (cuyos ítems de scaffolding ya están hechos). Reorganizado alrededor
del estado real al **2026-10-13**.

## Leyenda de estado
- `[x]` hecho y verificado (funcionalmente, contra containers).
- `[~]` **código hecho pero SIN verificación formal** (falta pest + PHPStan 8 + Pint + verificación real = Definición de Terminado de `CLAUDE.md`). La mayor parte del backend está aquí.
- `[ ]` pendiente (no empezado).

Los IDs (`V-03`, `P1-EXE-12`) son estables para citarlos en commits/PRs/issues.

---

## 0. Snapshot del estado actual (2026-10-13)

Construido con oleadas de agentes, en desarrollo, **sin commitear**:
- `[~]` 16 bounded contexts DDD/CQRS + `logistics/access` (RBAC). Outbox wireado en los 34 handlers (TransactionalCommandBus).
- `[~]` API de 3 puertas (app/channels/integrations) con Sanctum + roles; `openapi/v1.yaml`.
- `[~]` Admin Filament (~36 resources) con datos legibles + flujos transaccionales (acciones→CommandBus), i18n español (`lang/es.json`), tema de marca compilado (Tailwind v4), dashboard con widgets.
- `[~]` Mapas Leaflet + OpenStreetMap: editor de coberturas (PostGIS), mapa de ruta (planificada OSRM server-side + GPS road-snap), perfil de cliente, ubicación de pedido.
- `[~]` Ruteo VRP (Clarke-Wright + 2-opt sobre matriz OSRM `/table`), backend enchufable (builtin/osrm/vroom), auto-planner 2 modos (optimization/slots) async por colas, calendario de despacho + reserva de slots.
- `[~]` Notificaciones multicanal (WhatsApp, Telegram, FCM, web push, email, SMS), 14 tipos de mensaje con plantillas + interpolación, collage GD en push.
- `[~]` Datos de cliente (CI/teléfono/correo) + perfil; incidentes de camión (avería/accidente) con reprogramación automática.

**Lo que NO existe aún**: verificación formal de la DoD, app Flutter, canales reales (app clientes/web/call center), integración SAP/Magento reales, infra/deploy, datos reales de Fase 0, piloto.

---

## FASE V — Cierre técnico y Definición de Terminado (bloqueante, antes de producción)

Todo el backend está en `[~]`; nada cuenta como "terminado" sin esto.

### V-A. Verificación de la DoD
- `[x]` **V-01** Correr `pest` completo (Unit + Feature contra PostgreSQL) y dejar verde; reportar nº de tests/aserciones.
- `[x]` **V-02** `PHPStan` nivel 8 limpio en TODOS los packages (no solo labels); sin `@phpstan-ignore` injustificados.
- `[x]` **V-03** `Pint` limpio en todo el repo.
- `[ ]` **V-04** Verificación real contra containers de cada feature nueva (no solo tests): auto-planner, slots, incidentes, notificaciones, mapas, ruteo.
- `[x]` **V-05** `TestDatabaseIsolationTest` verde (jamás tocar la base de desarrollo; tests sobre `logistics_test`).
- `[ ]` **V-06** `make ci` verde localmente y en GitHub Actions.

### V-B. Cobertura de tests por feature nueva (muchas se construyeron sin tests)
- `[x]` **V-07** Tests del VRP (Clarke-Wright + 2-opt): determinismo, capacidad, invariante un-camión-un-departamento, fallback sin OSRM.
- `[x]` **V-08** Tests del auto-planner (ambos modos), incl. idempotencia del job y ejecución por lotes.
- `[x]` **V-09** Tests de slots/calendario: reserva de cupo atómica (sin sobre-reserva bajo concurrencia), idempotencia, liberación.
- `[x]` **V-10** Tests del flujo de incidente: MAJOR (reprogramación día siguiente + vehículo fuera de servicio), MINOR (retraso), solo paradas pendientes.
- `[x]` **V-11** Tests de la regla geográfica (mismo departamento, CD→CD exento) y del `DepartmentLocator` (PostGIS).
- `[x]` **V-12** Tests de notificaciones: cascada, idempotencia por shipment+tipo, interpolación de variables, degradación sin credenciales.
- `[ ]` **V-13** Tests de etiquetas (Ed25519) con el vector compartido; mantenerlo sincronizado con `logistics-app` si cambia `v`.
- `[ ]` **V-14** Tests de contrato OpenAPI (request/response) por endpoint de las 3 puertas.
- `[ ]` **V-15** Gate de cobertura mínima por package en CI.

### V-C. Higiene del repo
- `[ ]` **V-16** Revisar/consolidar migraciones del día (muchas `2026_10_13_*`); naming y orden coherentes.
- `[ ]` **V-17** Confirmar que cada package nuevo (`access`, etc.) esté en `bootstrap/providers.php` Y `dont-discover`.
- `[x]` **V-18** Re-fusionar `openapi/v1.yaml` desde los fragmentos `openapi/paths/*` y validar OpenAPI 3.
- `[ ]` **V-19** Quitar/parametrizar `vendor/filament` copiado al host (hack del build del tema); documentar el build del tema en el Makefile (`make assets`).
- `[x]` **V-20** `.gitignore`: asegurar que `node_modules`, `public/build`, `vendor` no entren al repo; decidir si `public/build` se versiona o se buildea en deploy.
- `[ ]` **V-21** Primer commit/PR real del trabajo (hoy todo es local sin commitear) con la skill `github-pr-flow`.

---

## FASE S — Seguridad y cumplimiento

- `[ ]` **S-01** Revisión de superficie de las 3 puertas (authn/authz, rate limit, validación de input, tamaño de payload).
- `[ ]` **S-02** Sanctum: rotación/expiración de tokens, flujo de dispositivo perdido/robado, revocación masiva.
- `[ ]` **S-03** Policies de Filament por rol (quién ve/edita qué recurso) alineadas a `App\Enums\Role`.
- `[ ]` **S-04** Credenciales por canal (`/channels/*`) con rate-limit por canal y rotación.
- `[ ]` **S-05** Credenciales de servicio (`/integrations/*`) para SAP/transportistas/webhooks; firma de webhooks.
- `[ ]` **S-06** Minimización de PII: etiquetas sin PII; cuidar PII en pedidos/tracking/notificaciones; retención y borrado.
- `[ ]` **S-07** Página pública de tracking: token no adivinable, rate-limit, expiración, sin PII sensible.
- `[ ]` **S-08** Auditoría inmutable de accesos y acciones sensibles (quién asignó/incidentó/reprogramó).
- `[ ]` **S-09** Rotación de claves de firma de etiquetas (`k`) operada; distribución de claves públicas a la app.
- `[ ]` **S-10** Secret scanning + revisión de dependencias (composer/npm) en CI; Dependabot/renovate.
- `[ ]` **S-11** Gestión de secretos fuera del repo (APP_KEY, claves etiquetas, FCM/VAPID, SAP, SMS, WhatsApp/Telegram) — vault/variables de entorno del orquestador.
- `[ ]` **S-12** Cabeceras de seguridad (CSP para el admin con los CDN de Leaflet/Geoman; HSTS; CORS por puerta).
- `[ ]` **S-13** Revisión de que el OSRM público no filtre coordenadas sensibles; evaluar self-host (ver I-xx).

---

## FASE O — Observabilidad y operación

- `[ ]` **O-01** Logging estructurado (JSON) con correlación por request/evento/dispositivo/job.
- `[ ]` **O-02** Métricas: entregas, escaneos, latencia API, profundidad de colas, outbox pendiente/fallido/dead-letter, duración del auto-planner, llamadas a OSRM.
- `[ ]` **O-03** Tracing de procesos asíncronos (evento→outbox→relay→conector/notificación; GenerateDailyPlan job).
- `[ ]` **O-04** Alertas: outbox atascado/dead-letter creciente, SAP caído, colas crecientes, fallas de notificación, 5xx, OSRM caído, job de planificación fallido.
- `[ ]` **O-05** Dashboard de operación (torre de control técnica) para el equipo de plataforma.
- `[ ]` **O-06** Captura de errores (Sentry o equivalente) en backend y app.
- `[ ]` **O-07** Panel Filament de outbox productivo: ver/ reintentar/ dead-letter (verificar el existente a fondo).
- `[ ]` **O-08** Healthchecks de readiness para orquestador: DB, PostGIS, Valkey, colas, S3, OSRM.
- `[ ]` **O-09** Runbooks de incidentes (OSRM caído, colas, SAP, notificaciones) y on-call.

---

## FASE I — Infraestructura, entornos y despliegue

- `[ ]` **I-01** `.docker/php` para prod (opcache afinado, sin dev-deps, límites) verificado.
- `[ ]` **I-02** Entornos **staging** y **production**: pipeline de deploy, migraciones controladas, rollback.
- `[ ]` **I-03** Build de assets en el pipeline (tema Filament Tailwind v4) sin el hack de copiar `vendor/filament` al host; imagen/stage con Node.
- `[ ]` **I-04** Workers y scheduler como servicios supervisados (reinicio, escalado horizontal del queue-worker); colas separadas (`planning`, `notifications`, `outbox`, `default`).
- `[ ]` **I-05** Scheduler: relay de outbox cada minuto, pruning de jobs/outbox, alertas de documentos por vencer, expiración de reservas de slots/promesa, auto-plan diario programado.
- `[ ]` **I-06** Almacenamiento S3-compatible para POD/checklist/daños/collages de push (credenciales + lifecycle + CDN).
- `[ ]` **I-07** Backups de PostgreSQL + PostGIS y **prueba de restauración**.
- `[ ]` **I-08** **OSRM self-host** con extracto OSM de Bolivia (preprocesado CH) para no depender del demo público; env `OSRM_HOST` apuntando al propio.
- `[ ]` **I-09** **VROOM self-host** (opcional) sobre el OSRM propio para VRP a gran escala; `VROOM_URL`.
- `[ ]` **I-10** Tiles OSM propios o proveedor con SLA (no abusar de `tile.openstreetmap.org` en prod) + política de uso.
- `[ ]` **I-11** FCM/VAPID: proyecto Firebase propio, service account, claves web-push (VAPID) gestionadas.
- `[ ]` **I-12** Gestión de secretos del orquestador (ver S-11).
- `[ ]` **I-13** Plan de capacidad: ~3 req/s de GPS, picos de notificación, auto-plan diario; pruebas de carga.

---

## FASE E — Escala y rendimiento (APIs a gran escala, datos y eventos)

- `[ ]` **E-01** Índices de BD revisados para los accesos calientes (envíos por estado/fecha/zona, paradas por ruta, outbox por status/available_at, tracking por token).
- `[ ]` **E-02** Paginación estándar + cursores en listados grandes de API y Filament.
- `[ ]` **E-03** Ingesta de GPS en vivo (~3 req/s/camión) con buffer/batch y partición temporal; separar de la base transaccional si hace falta.
- `[ ]` **E-04** Caché (Valkey) de ReadModels calientes (tracking, slots disponibles, matrices OSRM) en forma primitiva (regla #7).
- `[ ]` **E-05** Idempotencia de escrituras de API (clave de idempotencia) para la app offline — verificar en execution/incident/slots.
- `[ ]` **E-06** Outbox a escala: relay con `SKIP LOCKED` + lease ya está; validar throughput, backoff, dead-letter y reintentos bajo carga.
- `[ ]` **E-07** Auto-planner a escala: chunking, límites de `/table` de OSRM (submatrices), timeouts, reintentos; medir con ~300+ entregas.
- `[ ]` **E-08** Pruebas de carga: ingesta GPS, notificaciones, escaneo concurrente, auto-plan, reserva de slots concurrente.
- `[ ]` **E-09** Particionamiento/archivado del flujo de eventos inmutables (escaneos) para crecer a millones.

---

## FASE C — Refinamiento por bounded context

### network
- `[ ]` **C-NET-01** Modelo completo de corredores (los 6 tipos) y validación nodo-origen/destino por tipo.
- `[ ]` **C-NET-02** Shipment como secuencia lógica de tramos (ruta lógica) — decidir si vive en network o planning.
- `[ ]` **C-NET-03** Seeders de nodos reales (CDs, tiendas, puntos de recojo) de Fase 0.

### coverage
- `[ ]` **C-COV-01** Importador real KML/GeoJSON del mapa de cobertura actual por departamento, idempotente.
- `[ ]` **C-COV-02** CoverageRule por zona versionada vía settings (nodo que atiende, servicios, tiempos base, límites, horarios, días).
- `[ ]` **C-COV-03** Jerarquía completa Departamento>Provincia>Municipio>Ciudad>Zona>Microzona (hoy solo department/city).
- `[ ]` **C-COV-04** Visor de zonas enriquecido (capas, edición masiva) en el editor de mapa.

### fleet
- `[ ]` **C-FLT-01** Turnos y descansos de tripulación (hoy Crew mínimo).
- `[ ]` **C-FLT-02** Alertas automáticas de documentos por vencer/vencidos (scheduler + notificación).
- `[ ]` **C-FLT-03** Almacenamiento real de documentos (S3) + visor.
- `[ ]` **C-FLT-04** Estados de vehículo completos (disponible/mantenimiento/fuera de servicio por incidente) y su ciclo.

### shipments
- `[ ]` **C-SHP-01** Geocodificador real (Google/otro) detrás del contrato, con cache y cuota (hoy stub/placeholder).
- `[ ]` **C-SHP-02** Score de confianza de dirección con criterios/umbrales reales (Fase 0) y re-geocodificación.
- `[ ]` **C-SHP-03** Ingesta Excel real (plantilla F0) con validación y reporte de errores.
- `[ ]` **C-SHP-04** Adaptador Magento real (si entra al piloto).
- `[ ]` **C-SHP-05** Imagen real de producto (catálogo) en vez de placeholder para mail/push.

### planning
- `[ ]` **C-PLN-01** Plan de carga (orden inverso de entrega, carril) afinado y visible en la app del preparador.
- `[ ]` **C-PLN-02** Ajuste humano del plan (mover pedidos entre camiones) en el tablero.
- `[ ]` **C-PLN-03** Tablero React/Inertia con mapa (ADR 005) para arrastrar pedidos entre camiones (hoy solo acción Filament).
- `[ ]` **C-PLN-04** Consolidación por corredor entre departamentos (Fase 2) en planning.
- `[ ]` **C-PLN-05** Re-optimización en vivo ante cambios (nuevo pedido, incidente, cancelación).

### execution
- `[ ]` **C-EXE-01** Flujos completos de terreno end-to-end verificados: preparación→carga→ruta→descarga→POD→fallidas→recepción en nodos.
- `[ ]` **C-EXE-02** Offline-first real en la app (cola local + sync idempotente) — contrato del backend verificado.
- `[ ]` **C-EXE-03** Subida de fotos (POD/daños) a S3 con reintento.
- `[ ]` **C-EXE-04** Verificación offline de etiqueta firmada en el escaneo (claves públicas).
- `[ ]` **C-EXE-05** Recepción en nodos para todos los corredores (CD→CD, CD→tienda, etc.).

### tracking
- `[ ]` **C-TRK-01** Proyección de estado robusta desde el flujo de eventos (read model) con "paradas restantes" real.
- `[ ]` **C-TRK-02** Posición en vivo del camión expuesta (GPS) y en la página pública.
- `[ ]` **C-TRK-03** Página pública como gancho para instalar la app de clientes (ADR 006).

### notifications
- `[ ]` **C-NOT-01** Integración real FCM (proyecto Firebase de la app de clientes) + web push (VAPID) + email (proveedor prod) + SMS (proveedor + tope) + WhatsApp (Meta/Twilio) + Telegram (bot real).
- `[ ]` **C-NOT-02** Motor de cascada por resultado (abierto/no abierto) con reglas versionadas por tipo (settings).
- `[ ]` **C-NOT-03** Plantillas definitivas por evento/canal (es) + preferencias de notificación del cliente + opt-out.
- `[ ]` **C-NOT-04** Deliverability: reintentos, dead-letter, métricas de entrega/apertura, control de costos (SMS/WhatsApp).
- `[ ]` **C-NOT-05** Collage de push: imágenes reales de producto, caché, tamaños, fallback.

### capacity / promise (Fase 2)
- `[ ]` **C-CAP-01** Reglas de cupo configurables y versionadas (cortes, fechas, horarios, días) completas.
- `[ ]` **C-PRM-01** Cálculo de promesa integrado (cobertura + capacidad + corredor) expuesto a canales.
- `[ ]` **C-PRM-02** Reserva temporal de cupo al prometer; confirmación/expiración; conciliación con el slot elegido.

### sap-connector (Fase 2)
- `[ ]` **C-SAP-01** Lectura real de pedidos de SAP → modelo propio (mapeo bulto↔material↔centro↔almacén).
- `[ ]` **C-SAP-02** Traspasos de stock al recibir en nodo: evento→outbox→SAP idempotente por `event_id`, con reintentos.
- `[ ]` **C-SAP-03** Panel de reconciliación de traspasos pendientes/fallidos (verificar el existente).
- `[ ]` **C-SAP-04** Tolerancia a caídas de SAP (el escaneo nunca espera a SAP).

### carriers (Fase 2)
- `[ ]` **C-CAR-01** Transportistas, tarifas por corredor, calificación/desempeño completos.
- `[ ]` **C-CAR-02** Asignación por volumen (tráiler/camión/tercero) para consolidación entre departamentos.
- `[ ]` **C-CAR-03** API `/integrations` para transportistas (estados, documentos, liquidación).

### labels
- `[ ]` **C-LBL-01** Generación ZPL para Zebra ZQ630 verificada en impresora real.
- `[ ]` **C-LBL-02** Pre-emisión al planificar (worker/listener de plan publicado) y asociación a unidades.
- `[ ]` **C-LBL-03** Detección de duplicados y revocación por reimpresión.

### access / outbox / settings / shared
- `[ ]` **C-ACC-01** Gestión completa de usuarios/roles/permisos en el admin; tokens de integración; auditoría.
- `[ ]` **C-OBX-01** Relay productivo + dead-letter + reintentos verificados bajo carga; MessageDispatcher fan-out robusto.
- `[ ]` **C-SET-01** Config versionada con historial y UI para TODAS las reglas (coberturas, cupos, cortes, tarifas, notificaciones, auto-plan).

---

## FASE R — Ruteo y mapas (optimización avanzada)

- `[ ]` **R-01** OSRM self-host Bolivia (ver I-08) → ruteo real por calles sin límites del demo.
- `[ ]` **R-02** VROOM self-host (ver I-09) → VRP con **ventanas horarias (VRPTW)**, múltiples capacidades, múltiples depósitos.
- `[ ]` **R-03** Ventanas horarias en el VRP (respetar la franja/slot del cliente como restricción dura).
- `[ ]` **R-04** Restricciones de vehículo (tipo de carga, zonas permitidas, altura/peso) en el solver.
- `[ ]` **R-05** Re-optimización incremental (nuevo pedido/cancelación/incidente) sin rehacer todo.
- `[ ]` **R-06** Métricas de calidad de ruta (distancia/tiempo/ocupación) y comparación de backends.
- `[ ]` **R-07** Geocoder real (ver C-SHP-01) y road-snap server-side cacheado del GPS (hoy client-side).
- `[ ]` **R-08** Persistir la geometría al generar el plan en producción (hoy pre-cargada solo en demo).
- `[ ]` **R-09** Mapas: tiles propios/SLA (I-10), clustering de marcadores a escala, capas (zonas+rutas+camiones en vivo).

---

## FASE A — App interna Flutter (`logistics-app`, repo aparte)

- `[ ]` **A-01** Scaffold Flutter + generación del cliente Dart desde `openapi/v1.yaml` (pipeline).
- `[ ]` **A-02** Login usuario + dispositivo; manejo de token; vista por rol.
- `[ ]` **A-03** Verificación offline de etiquetas (libsodium Dart) con el vector compartido.
- `[ ]` **A-04** Escaneo (cámara/lector) EAN y QR; impresión ZPL a Zebra por BT/WiFi.
- `[ ]` **A-05** Flujo preparador (preparar por carriles).
- `[ ]` **A-06** Flujo chofer: carga, ruta (con mapa + navegación), descarga, POD, fallidas, GPS en vivo, **reportar incidente**.
- `[ ]` **A-07** Flujo tienda / transportista / supervisor (mínimo del piloto).
- `[ ]` **A-08** Modo offline: cola local de eventos, sync idempotente al recuperar señal.
- `[ ]` **A-09** Distribución por Managed Google Play (interno) + Firebase propio.
- `[ ]` **A-10** Gestión de versiones (compatibilidad `/v1`, choferes con versión vieja).
- `[ ]` **A-11** Pruebas E2E preparar→cargar→entregar contra staging.

---

## FASE K — Canales y clientes

- `[ ]` **K-01** API `/channels/*` estable para app de clientes / web / call center.
- `[ ]` **K-02** Tracking público: ETA, estado, "a N paradas", mapa, sin PII.
- `[ ]` **K-03** Selección de slot por el cliente (día+franja) integrada punta a punta (web/app).
- `[ ]` **K-04** Web push en la página de tracking (suscripción al topic del envío).
- `[ ]` **K-05** Promesa de entrega expuesta a los canales (fechas/franjas disponibles).
- `[ ]` **K-06** Encuesta de satisfacción post-entrega (instrumento Fase 0) y captura.

---

## FASE 0 — Discovery operativo (datos reales, requiere accesos del negocio)

- `[ ]` **F0-01** Línea base de los 5 KPIs del piloto (método + medición previa) y metas de salida.
- `[ ]` **F0-02** Exportar y medir calidad de coordenadas/direcciones actuales; definir score real.
- `[ ]` **F0-03** Mapa de cobertura actual (KML/GeoJSON) por departamento e importarlo.
- `[ ]` **F0-04** Inventario real de flota, choferes/ayudantes, nodos (CDs/tiendas/puntos de recojo).
- `[ ]` **F0-05** Formatos reales de pedidos (SAP, Magento, Excel) y mapeo bulto↔material↔centro↔almacén.
- `[ ]` **F0-06** Acceso al proyecto Firebase de la app de clientes (topics FCM) y medición de % con app activa.
- `[ ]` **F0-07** Inventario de impresoras Zebra (modelos/firmware/conectividad) y celulares de la operación.
- `[ ]` **F0-08** Proveedor y costo de SMS/WhatsApp de respaldo y tope mensual.

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
- `[ ]` **3-05** Simulador de escenarios (flota, cupos, coberturas) sobre datos reales; comparar políticas.
- `[ ]` **3-06** Rentabilidad por entrega/corredor (ingreso vs costo real) + tableros/alertas.
- `[ ]` **3-07** Analítica/BI sobre el flujo inmutable de eventos (puntualidad, fallos, calidad de coordenadas).
- `[ ]` **3-08** Conectar dis-commerce como un adaptador más de pedidos (~6 meses).
- `[ ]` **3-09** Lockers / agentes como tipos de nodo nuevos (si entran en alcance).

---

## Workstreams continuos (todas las fases)

- `[ ]` **CONT-01** `openapi/v1.yaml` sincronizado en cada cambio de endpoint.
- `[ ]` **CONT-02** Vector de etiquetas idéntico en ambos repos si cambia `v`.
- `[ ]` **CONT-03** Nueva ADR por cada decisión de arquitectura (OSRM/VROOM self-host, WhatsApp vs ADR 006, lockers, dis-commerce, outbox compartido).
- `[ ]` **CONT-04** Límites entre packages intactos (nadie importa Infrastructure de otro contexto).
- `[ ]` **CONT-05** Documentación viva (`docs/`, READMEs de package, memoria del agente) al día.
- `[ ]` **CONT-06** Revisión periódica de costos (FCM, SMS, WhatsApp, geocoder, tiles, OSRM/VROOM).
- `[ ]` **CONT-07** Seguridad continua (rotación de claves, dependencias, secret scanning, accesos).
- `[ ]` **CONT-08** Versionado de la app (`/v1` → `/v2` solo con cambio incompatible) y compatibilidad con choferes en versión vieja.

---

## Decisiones a tomar (abiertas)
- `[ ]` **D-01** ADR: activar WhatsApp contradice ADR 006 ("sin WhatsApp por costo") — formalizar la decisión del dueño.
- `[ ]` **D-02** ADR: ruteo — OSRM+VROOM self-host vs Google Route Optimization (ADR 005 mencionaba Google); el proyecto va con OSM.
- `[ ]` **D-03** ¿`public/build` versionado o buildeado en deploy?
- `[ ]` **D-04** ¿Extraer `outbox`/`settings`/`shared` a repo compartido o quedan como packages locales?
- `[ ]` **D-05** ¿Geocoder: Google vs alternativa OSM (Nominatim self-host)?

---

### Prioridad sugerida
1. **Fase V** (cierre técnico + primer commit) — desbloquea todo lo demás y vuelve "real" lo construido.
2. **Fase I + S + O** mínimas para staging seguro y observable.
3. **Fase A** (app Flutter) + **C-EXE/C-TRK/C-NOT reales** — sin app no hay piloto.
4. **Fase 0** (datos reales, en paralelo, lo maneja el negocio).
5. **Piloto (Fase P)**.
6. **Fase 2 real** (SAP/promise/capacity/carriers) y **Fase R** (ruteo avanzado).
7. **Fase 3** (optimización) post-piloto.
