# Roadmap — Plataforma logística Dismac

Roadmap completo y exhaustivo de todo lo pendiente para llegar a la visión de `docs/vision.md`:
una plataforma propia (fuera de SAP) que **planifica, ejecuta y hace visible** cada entrega de la red
completa (CD→cliente, CD→CD, CD→tienda, tienda→tienda, tienda→CD y recojo en tienda), a escala de
~100 camiones y ~300 entregas diarias.

> **Cómo leer este documento.** Cada ítem es una casilla `[ ]`. Agrupados por **fase** (0→3, de
> `docs/vision.md`) y por **workstream**. Un ítem no está "hecho" hasta cumplir la **Definición de
> Terminado** (DoD) del final. Los IDs (`P1-SHP-03`) son estables para citarlos en commits, PRs e issues.
> El orden dentro de cada fase respeta dependencias; entre workstreams hay paralelismo posible.

## Leyenda de estado

- `[x]` hecho y verificado contra containers.
- `[~]` parcial / en progreso.
- `[ ]` pendiente.

## Estado actual (línea base, 2026-10)

- `[x]` Scaffold Laravel 13 (PHP 8.4), estructura de monolito modular.
- `[x]` Docker: nginx, php-fpm, PostgreSQL 17 + PostGIS, Valkey, Mailpit; `queue-worker` y `scheduler` siempre encendidos.
- `[x]` Makefile (`install`, `test`, `lint`, `ci`, `shell`, `reload`), `.env.example`, `.editorconfig`.
- `[x]` CI GitHub Actions: `ci.yml` (test + lint) y `branch-policy.yml`. PR template.
- `[x]` Filament 4 instalado (`AdminPanelProvider`).
- `[x]` Endpoint `/api/v1/health` + su contrato en `openapi/v1.yaml`.
- `[x]` Package `labels` **completo**: `LabelPayload`, `Fraction`, `LabelType`, `SignedLabel`, `VerificationResult`, firma/verificación Ed25519 (libsodium), comando `logistics:labels:keygen`, vector de prueba compartido y tests Unit.
- `[~]` 8 packages de Fase 1 **solo esqueleto** (composer.json + ServiceProvider vacío): `network`, `coverage`, `fleet`, `shipments`, `planning`, `execution`, `tracking`, `notifications`.
- `[ ]` 4 packages de Fase 2 **sin crear**: `capacity`, `promise`, `sap-connector`, `carriers`.
- `[ ]` Patrones compartidos `outbox` y `settings` (reutilizar/portar de dis-commerce) **sin crear**.
- `[ ]` App interna Flutter (`logistics-app`): repo aparte, sin trabajo reflejado aquí.

---

# FASE 0 — Discovery (línea base, antes/junto al MVP)

Objetivo: tener números y materia prima reales antes de prometer nada. No requiere código de producto,
pero sí instrumentación y análisis.

## F0-KPI — Línea base de KPIs del piloto

- `[ ]` F0-KPI-01 Definir fórmula y fuente de cada KPI del piloto (ver `vision.md`): llamadas chofer→cliente, consultas "¿dónde está mi pedido?", % entregas a tiempo/fallidas con motivo, bultos descargados por error/faltantes, satisfacción post-entrega.
- `[ ]` F0-KPI-02 Medir la línea base **actual** (sin plataforma) de cada KPI, con el método que se usará luego, para comparar.
- `[ ]` F0-KPI-03 Definir metas numéricas de salida del piloto por KPI.
- `[ ]` F0-KPI-04 Acordar instrumento de "satisfacción post-entrega" (canal, pregunta, escala) y su captura.
- `[ ]` F0-KPI-05 Tablero de seguimiento de KPIs del piloto (puede ser manual al inicio).

## F0-GEO — Calidad de coordenadas y mapa

- `[ ]` F0-GEO-01 Exportar la base de direcciones/coordenadas actuales y medir calidad (precisión, duplicados, nulos, fuera de zona).
- `[ ]` F0-GEO-02 Obtener el mapa de cobertura actual por departamento (KML/GeoJSON) y catalogar formato, capas y vigencia.
- `[ ]` F0-GEO-03 Inventario de fuentes de geocodificación disponibles y su costo/cuota (Google, interno).
- `[ ]` F0-GEO-04 Definir el score de confianza de dirección (criterios y umbrales) que usará `shipments`.

## F0-FLEET — Flota real

- `[ ]` F0-FLEET-01 Inventario real de la flota (vehículos, capacidades kg/m³, CD base, estado, documentos).
- `[ ]` F0-FLEET-02 Inventario de choferes y ayudantes (licencias, vencimientos, habilitaciones).
- `[ ]` F0-FLEET-03 Catálogo de nodos reales: CDs, tiendas, puntos de recojo, con direcciones y capacidades.
- `[ ]` F0-FLEET-04 Mapa de corredores entre departamentos y volúmenes aproximados (insumo para consolidación de Fase 2).

## F0-SRC — Fuentes de pedidos

- `[ ]` F0-SRC-01 Relevar formato real de pedidos de SAP (campos, materiales, centro/almacén) sin integrarse aún.
- `[ ]` F0-SRC-02 Relevar formato del Magento actual (API/export) como fuente de pedidos.
- `[ ]` F0-SRC-03 Definir el formato de carga manual / Excel (plantilla) para pedidos.
- `[ ]` F0-SRC-04 Documentar el mapeo bulto↔material↔centro↔almacén (insumo del `sap-connector`).

## F0-FIRE — Acceso Firebase y dispositivos

- `[ ]` F0-FIRE-01 Conseguir acceso al proyecto Firebase de la app de clientes (para publicar en topics FCM).
- `[ ]` F0-FIRE-02 Medir % de entregas cuyo cliente tiene la app con notificaciones activas (riesgo de ADR 006).
- `[ ]` F0-FIRE-03 Inventario de impresoras Zebra (modelos, firmware, conectividad BT/WiFi) y celulares de la operación.
- `[ ]` F0-FIRE-04 Definir proveedor y costo de SMS de respaldo (y tope mensual).

---

# FASE 1 — Operar (MVP)

Meta demostrable: **"Sabemos qué sale, en qué camión, con quién, y el cliente lo ve."** Piloto en una
ciudad, 10–15 camiones, corredor CD→cliente. Todo lo de abajo es Fase 1 salvo que diga otra fase.

## WORKSTREAM A — Plataforma y cimientos transversales

Base que todos los packages usan. Bloquea casi todo lo demás; hacer temprano.

### P1-PLAT — Patrones base (outbox, settings, eventos)

- `[ ]` P1-PLAT-01 Decidir: extraer `dismac/outbox` y `dismac/settings` a repo compartido **o** copiarlos a `packages/` (ADR nuevo).
- `[ ]` P1-PLAT-02 Portar/crear package `outbox`: tabla, modelo, encolado, worker de despacho, reintentos con backoff, idempotencia por `event_id`, dead-letter.
- `[ ]` P1-PLAT-03 Panel Filament de outbox: ver pendientes, fallidos, reintentar, dead-letter.
- `[ ]` P1-PLAT-04 Portar/crear package `settings`: reglas configurables y **versionadas** (coberturas, cupos, cortes, tarifas) con historial y auditoría.
- `[ ]` P1-PLAT-05 Infraestructura de **eventos de dominio inmutables**: contrato común `DomainEvent`, almacén append-only (hora, GPS, usuario, dispositivo), publicación a listeners y a outbox.
- `[ ]` P1-PLAT-06 Bus/dispatcher interno de eventos entre bounded contexts (sin acoplar Infrastructure de un context con otro).
- `[ ]` P1-PLAT-07 Convención de IDs públicos (ULID/UUID) y separación de IDs internos; helper compartido.
- `[ ]` P1-PLAT-08 Librería compartida de Value Objects geográficos (`Coordinate`, `Distance`) reutilizable por coverage/planning/tracking.
- `[ ]` P1-PLAT-09 Manejo de errores de API uniforme (RFC 7807 problem+json) y catálogo de códigos de error.
- `[ ]` P1-PLAT-10 Base de reglas de cache: cachear forma primitiva (array), nunca el objeto de dominio (regla CLAUDE.md #7); helpers y tests.

### P1-AUTH — Autenticación, autorización y dispositivos

- `[ ]` P1-AUTH-01 Sanctum: tokens **por usuario + dispositivo** para la app interna (`/api/v1/app/*`).
- `[ ]` P1-AUTH-02 Registro y gestión de **dispositivos**: alta, binding usuario-dispositivo, revocación, último visto.
- `[ ]` P1-AUTH-03 Modelo de **roles**: chofer, preparador, tienda, transportista, supervisor, admin (+ permisos por endpoint).
- `[ ]` P1-AUTH-04 Credenciales **por canal** (`/api/v1/channels/*`) con rate limit por canal.
- `[ ]` P1-AUTH-05 Credenciales de **servicio** (`/api/v1/integrations/*`) para SAP/transportistas/webhooks.
- `[ ]` P1-AUTH-06 Login/logout de la app interna + rotación/expiración de tokens; flujo de dispositivo perdido.
- `[ ]` P1-AUTH-07 Auditoría de accesos y acciones sensibles.
- `[ ]` P1-AUTH-08 Policies de Filament (quién ve qué en el admin) alineadas a roles.

### P1-API — Las tres puertas de la API

- `[ ]` P1-API-01 Estructura de rutas y middleware de las tres puertas: `app`, `channels`, `integrations`.
- `[ ]` P1-API-02 Versionado `/v1` y política de cambios incompatibles (`/v2`) documentada y testeada.
- `[ ]` P1-API-03 Rate limiting, paginación estándar, filtros y envelopes de respuesta comunes.
- `[ ]` P1-API-04 Idempotencia de requests de escritura (clave de idempotencia) para la app offline.
- `[ ]` P1-API-05 `openapi/v1.yaml` como fuente de verdad: linter de spec en CI y regla "endpoint nuevo = spec en el mismo cambio".
- `[ ]` P1-API-06 Validación de requests/responses contra el schema OpenAPI en tests de contrato.
- `[ ]` P1-API-07 Publicación del cliente Dart generado para `logistics-app` (pipeline de generación).

### P1-INFRA — Infraestructura, entornos y despliegue

- `[ ]` P1-INFRA-01 `.docker/php` listo para prod (opcache, límites, sin dev deps) y verificado.
- `[ ]` P1-INFRA-02 Almacenamiento S3-compatible para POD, checklist y fotos de daños (config + credenciales + lifecycle).
- `[ ]` P1-INFRA-03 Entornos `staging` y `production`: pipeline de deploy, migraciones controladas, rollback.
- `[ ]` P1-INFRA-04 Gestión de secretos (APP_KEY, claves de firma de etiquetas, credenciales FCM/SAP/SMS) fuera del repo.
- `[ ]` P1-INFRA-05 Backups de PostgreSQL + PostGIS y prueba de restauración.
- `[ ]` P1-INFRA-06 Workers y scheduler como servicios supervisados (reinicio, escalado horizontal del `queue-worker`).
- `[ ]` P1-INFRA-07 Health/readiness checks para orquestador (más allá del `/health` actual): DB, PostGIS, Valkey, colas, S3.
- `[ ]` P1-INFRA-08 Plan de capacidad para ~3 req/s de GPS y picos de notificación.

### P1-OBS — Observabilidad y operación

- `[ ]` P1-OBS-01 Logging estructurado (JSON) con correlación por request/evento/dispositivo.
- `[ ]` P1-OBS-02 Métricas (entregas, escaneos, latencia API, profundidad de colas, outbox pendiente/fallido).
- `[ ]` P1-OBS-03 Tracing de procesos asíncronos (evento→outbox→conector/notificación).
- `[ ]` P1-OBS-04 Alertas: outbox atascado, SAP caído, colas crecientes, fallas de notificación, errores 5xx.
- `[ ]` P1-OBS-05 Dashboard de operación (torre de control técnica) para el equipo de plataforma.
- `[ ]` P1-OBS-06 Captura de errores (Sentry o equivalente) en backend y app.

### P1-SEC — Seguridad y cumplimiento

- `[ ]` P1-SEC-01 Revisión de superficie de las tres puertas (authn/authz, rate limit, input validation).
- `[ ]` P1-SEC-02 Rotación de claves de firma de etiquetas (`k`) documentada y operada (ya soportada por diseño).
- `[ ]` P1-SEC-03 Minimización de datos personales (etiquetas sin PII; cuidar PII en pedidos/tracking).
- `[ ]` P1-SEC-04 Protección de la página pública de tracking (token no adivinable, rate limit, expiración).
- `[ ]` P1-SEC-05 Registro/auditoría inmutable de escaneos y accesos.
- `[ ]` P1-SEC-06 Revisión de dependencias y secret scanning en CI.

### P1-QA — Testing, calidad y CI

- `[ ]` P1-QA-01 `TestDatabaseIsolationTest` activo y verde (nunca tocar base de desarrollo).
- `[ ]` P1-QA-02 PHPStan nivel 8 limpio en todos los packages (no solo labels).
- `[ ]` P1-QA-03 Pint limpio en todo el repo; regla en CI.
- `[ ]` P1-QA-04 Suite Pest: Unit (sin Laravel) + Feature (stack contra PostgreSQL real) por package.
- `[ ]` P1-QA-05 Factories y seeders de datos de demo realistas (nodos, flota, pedidos, zonas).
- `[ ]` P1-QA-06 Tests de contrato OpenAPI (request/response) por endpoint.
- `[ ]` P1-QA-07 Cobertura mínima acordada por package y gate en CI.
- `[ ]` P1-QA-08 Tests de carga básicos (ingesta GPS, notificaciones, escaneo concurrente).

## WORKSTREAM B — Bounded contexts de Fase 1 (dominio)

Cada package sigue las 4 capas (Domain/Application/Infrastructure/Presentation) y su DoD. Orden sugerido:
network → coverage → fleet → shipments → planning → execution → tracking → notifications.

### P1-NET — `logistics/network` (nodos y tramos)

- `[ ]` P1-NET-01 Domain: `Node` (CD, tienda, punto de recojo) con tipo, capacidades, ubicación, estado.
- `[ ]` P1-NET-02 Domain: `Leg`/tramo entre nodos y los 6 tipos de corredor (CD→cliente, CD→CD, CD→tienda, tienda→tienda, tienda→CD, recojo en tienda).
- `[ ]` P1-NET-03 Domain: `Shipment` como **secuencia de tramos** entre nodos (modelo de ruta lógica, no física).
- `[ ]` P1-NET-04 Infrastructure: persistencia Eloquent + migraciones (nodos, tramos) con PostGIS para ubicación.
- `[ ]` P1-NET-05 Application: comandos/queries (alta de nodo, listar nodos por tipo/zona, definir corredores).
- `[ ]` P1-NET-06 Presentation: Filament CRUD de nodos y tramos (texto en inglés, selects `->native(false)`).
- `[ ]` P1-NET-07 Registrar package en `bootstrap/providers.php` y `dont-discover` (regla CLAUDE.md #3).
- `[ ]` P1-NET-08 Tests Unit + Feature; PHPStan 8; Pint; seeders de nodos reales.

### P1-COV — `logistics/coverage` (cobertura geográfica)

- `[ ]` P1-COV-01 Domain: jerarquía Departamento > Provincia > Municipio > Ciudad > Zona (polígono) > Microzona.
- `[ ]` P1-COV-02 Domain: `Zone` como polígono PostGIS; `CoverageRule` por zona (nodo que atiende, servicios, tiempos base, límites por producto, restricciones horarias, días de servicio).
- `[ ]` P1-COV-03 Infrastructure: resolución **punto→zona** con índice espacial PostGIS.
- `[ ]` P1-COV-04 Infrastructure: importador del **mapa de cobertura actual** (KML/GeoJSON) por departamento, idempotente.
- `[ ]` P1-COV-05 Application: query "¿qué zona/nodo/reglas aplican a esta coordenada?".
- `[ ]` P1-COV-06 Reglas **versionadas** vía `settings` (cambios con historial).
- `[ ]` P1-COV-07 Presentation: Filament para jerarquía y reglas; visor de zonas (apoyo del tablero React).
- `[ ]` P1-COV-08 Registrar package; tests (incluye casos límite de geometría); PHPStan 8; Pint; seeders de zonas.

### P1-FLT — `logistics/fleet` (flota y tripulación)

- `[ ]` P1-FLT-01 Domain: `Vehicle` (capacidad kg/m³, CD base, estado, documentos con vencimiento).
- `[ ]` P1-FLT-02 Domain: `Driver` (licencia, vencimiento, vehículos habilitados) y `Helper`/ayudante.
- `[ ]` P1-FLT-03 Domain: `Crew`/tripulación, turnos y descansos; `PreTripChecklist`.
- `[ ]` P1-FLT-04 Domain: alertas por documentos próximos a vencer / vencidos.
- `[ ]` P1-FLT-05 Infrastructure: persistencia + migraciones + almacenamiento de documentos (S3).
- `[ ]` P1-FLT-06 Application: asignar tripulación, habilitar/inhabilitar vehículo, validar checklist pre-salida.
- `[ ]` P1-FLT-07 Presentation: Filament CRUD de vehículos, choferes, ayudantes, tripulaciones; checklist.
- `[ ]` P1-FLT-08 Registrar package; tests; PHPStan 8; Pint; seeders de flota real (F0-FLEET).

### P1-SHP — `logistics/shipments` (recepción de pedidos)

- `[ ]` P1-SHP-01 Domain: `Shipment`, `ShipmentLine`, `PhysicalUnit`/bulto (unidad N de M, pieza N de M).
- `[ ]` P1-SHP-02 Domain: normalización al modelo propio desde múltiples fuentes (sin materiales SAP, sin validar stock).
- `[ ]` P1-SHP-03 Domain: dirección + **score de confianza** de la dirección.
- `[ ]` P1-SHP-04 Infrastructure: **geocodificación** detrás de interfaz (Google primero) con cache y cuota.
- `[ ]` P1-SHP-05 Application: **API única de ingreso** de pedidos (caso de uso de recepción) idempotente.
- `[ ]` P1-SHP-06 Ingesta: carga **manual/Excel** (plantilla F0-SRC-03) con validación y reporte de errores.
- `[ ]` P1-SHP-07 Ingesta: fuente **Magento actual** (adaptador) — si entra en el piloto.
- `[ ]` P1-SHP-08 Presentation: `/api/v1/integrations/*` de recepción + Filament para ver/editar pedidos recibidos y re-geocodificar.
- `[ ]` P1-SHP-09 Emitir evento de dominio "pedido recibido" (lo consumen planning/labels/tracking).
- `[ ]` P1-SHP-10 Registrar package; tests; PHPStan 8; Pint; actualizar `openapi/v1.yaml`; seeders de pedidos.

### P1-LBL — `logistics/labels` (etiquetas) — cerrar lo que falta

- `[x]` P1-LBL-01 Firma/verificación Ed25519, `LabelPayload`, `Fraction`, keygen, vector de prueba, tests Unit. **(hecho)**
- `[ ]` P1-LBL-02 **Generación ZPL** para Zebra (ZQ630 Plus) a partir de `SignedLabel`.
- `[ ]` P1-LBL-03 **Pre-emisión** de etiquetas al planificar (worker) y asociación a unidades del pedido.
- `[ ]` P1-LBL-04 Endpoint(s) de la app para obtener/emitir etiquetas de un pedido (offline-friendly).
- `[ ]` P1-LBL-05 Rotación de claves operada + distribución de **claves públicas** a la app (bundle que la app trae).
- `[ ]` P1-LBL-06 Detección de duplicados (mismo `id` en dos lugares) y revocación por reimpresión.
- `[ ]` P1-LBL-07 Tests Feature de emisión/pre-emisión; mantener vector sincronizado con `logistics-app` si cambia `v`.
- `[ ]` P1-LBL-08 Documentar y testear el contrato de QR como estable para la app (ADR 004).

### P1-PLN — `logistics/planning` (planificación)

- `[ ]` P1-PLN-01 Domain: modelo de **plan del día**, asignación pedido→camión, secuencia de paradas, ETA.
- `[ ]` P1-PLN-02 Domain: **plan de carga** (orden invertido de entrega, carril) por camión.
- `[ ]` P1-PLN-03 Infrastructure: **motor de rutas** detrás de interfaz propia — adaptador Google Route Optimization primero.
- `[ ]` P1-PLN-04 Application: generar **propuesta automática** pedido→camión respetando cobertura, capacidad y tripulación.
- `[ ]` P1-PLN-05 Application: **ajuste humano** (mover pedidos entre camiones) y **publicación** del plan.
- `[ ]` P1-PLN-06 Cálculo de ETA por parada y de la ruta; recálculo ante cambios.
- `[ ]` P1-PLN-07 Presentation (React/Inertia + Google Maps): **tablero de planificación** con arrastrar pedidos entre camiones sobre mapa (ADR 005).
- `[ ]` P1-PLN-08 Emitir evento "plan publicado" (dispara pre-emisión de etiquetas y disponibilidad en la app).
- `[ ]` P1-PLN-09 Registrar package; tests (incl. motor de rutas mockeado); PHPStan 8; Pint; `openapi` de los endpoints del tablero.

### P1-EXE — `logistics/execution` (ejecución en terreno)

- `[ ]` P1-EXE-01 Domain: **evento de escaneo inmutable** (hora, GPS, usuario, dispositivo) como base de todo.
- `[ ]` P1-EXE-02 Preparación: escaneo EAN → etiqueta → carril; validación de que se prepara lo correcto.
- `[ ]` P1-EXE-03 **Carga validada**: escaneo de bultos al subir al camión; detectar faltantes/sobrantes.
- `[ ]` P1-EXE-04 **Ruta del chofer**: secuencia de paradas, navegación, estado por parada.
- `[ ]` P1-EXE-05 **Descarga guiada** por parada (qué baja en cada punto) con escaneo.
- `[ ]` P1-EXE-06 **Prueba de entrega (POD)**: QR del cliente / confirmación, foto, firma, geocerca de llegada.
- `[ ]` P1-EXE-07 **Entregas fallidas** con catálogo de motivos y reprogramación.
- `[ ]` P1-EXE-08 **Recepción en nodos** (CD→CD, CD→tienda, etc.) — lo esencial para la red de Fase 1.
- `[ ]` P1-EXE-09 Infrastructure: endpoints `/api/v1/app/*` de ejecución, **idempotentes y offline-first** (cola de eventos en la app, sync al recuperar señal).
- `[ ]` P1-EXE-10 Infrastructure: subida de fotos (POD/daños) a S3 con reintento.
- `[ ]` P1-EXE-11 Application: cada acción emite evento que consumen tracking y notifications.
- `[ ]` P1-EXE-12 Verificación de **etiqueta firmada offline** en el escaneo (usa claves públicas).
- `[ ]` P1-EXE-13 Registrar package; tests Unit/Feature (incl. concurrencia y sync offline); PHPStan 8; Pint; `openapi`.

### P1-TRK — `logistics/tracking` (visibilidad)

- `[ ]` P1-TRK-01 Domain: estado visible por envío y por bulto; paradas restantes; ETA.
- `[ ]` P1-TRK-02 Proyección de estado a partir del flujo de eventos de `execution` (read model).
- `[ ]` P1-TRK-03 Ingesta de **posición en vivo del camión** (GPS, ~3 req/s) y exposición de posición.
- `[ ]` P1-TRK-04 **Página pública de tracking por token** (sin PII sensible, con ETA y estado).
- `[ ]` P1-TRK-05 Endpoint `/api/v1/channels/*` de tracking para app de clientes / web / call center.
- `[ ]` P1-TRK-06 La página pública como gancho para **instalar la app de clientes** (ADR 006).
- `[ ]` P1-TRK-07 Registrar package; tests; PHPStan 8; Pint; `openapi`.

### P1-NOT — `logistics/notifications` (cascada de canales)

- `[ ]` P1-NOT-01 Domain: **reglas configurables por tipo de mensaje** (qué canal, cuándo, tope) vía `settings`.
- `[ ]` P1-NOT-02 Canal **Push FCM por topic de envío** (`shipment_<id>`): publicar sin conocer tokens (ADR 006).
- `[ ]` P1-NOT-03 Canal **Web push** en la página de tracking: suscribir token del cliente al mismo topic.
- `[ ]` P1-NOT-04 Canal **Email** transaccional (Mailpit en dev; proveedor real en prod).
- `[ ]` P1-NOT-05 Canal **SMS de respaldo crítico** con tope configurable (solo baja confianza y sin apertura de push/email).
- `[ ]` P1-NOT-06 Motor de **cascada**: elegir canal según reglas y resultado (abierto/no abierto).
- `[ ]` P1-NOT-07 Todo el envío por **outbox** (nada síncrono en el request); consumir eventos de execution/tracking.
- `[ ]` P1-NOT-08 Plantillas de mensajes (en-ruta, llegando, entregado, fallido, confirmar ubicación).
- `[ ]` P1-NOT-09 Integración real con el proyecto Firebase de la app de clientes (F0-FIRE-01).
- `[ ]` P1-NOT-10 Registrar package; tests; PHPStan 8; Pint; panel Filament de reglas y log de envíos.

## WORKSTREAM C — App interna Flutter (`logistics-app`, repo aparte)

Coordinado por el contrato `openapi/v1.yaml`. Se lista aquí para el roadmap completo aunque viva en otro repo.

- `[ ]` P1-APP-01 Scaffold Flutter + generación del cliente Dart desde `openapi/v1.yaml`.
- `[ ]` P1-APP-02 Login por usuario + dispositivo; manejo de token; pantalla por rol.
- `[ ]` P1-APP-03 Verificación **offline** de etiquetas (libsodium Dart) con el vector de prueba compartido.
- `[ ]` P1-APP-04 Escaneo (cámara/lector) de EAN y QR; impresión ZPL a Zebra por BT/WiFi.
- `[ ]` P1-APP-05 Flujo **preparador**: preparar pedido por carriles.
- `[ ]` P1-APP-06 Flujo **chofer**: carga, ruta, descarga, POD, fallidas, GPS en vivo.
- `[ ]` P1-APP-07 Flujo **tienda / transportista / supervisor** (lo mínimo del piloto).
- `[ ]` P1-APP-08 **Modo offline**: cola local de eventos, sync idempotente al recuperar señal.
- `[ ]` P1-APP-09 Distribución por **Managed Google Play** (interno) + proyecto Firebase propio.
- `[ ]` P1-APP-10 Pruebas E2E del circuito preparar→cargar→entregar contra backend de staging.

## WORKSTREAM D — Piloto (cierre de Fase 1)

- `[ ]` P1-PIL-01 Seleccionar la ciudad y los 10–15 camiones del piloto; cargar sus datos reales.
- `[ ]` P1-PIL-02 Importar zonas de cobertura de esa ciudad y validar punto→zona con casos reales.
- `[ ]` P1-PIL-03 Entrenar a la operación (preparadores, choferes, supervisor).
- `[ ]` P1-PIL-04 Correr el circuito completo CD→cliente end-to-end en staging y luego en producción acotada.
- `[ ]` P1-PIL-05 Medir los 5 KPIs del piloto contra la línea base (F0-KPI) y publicar resultados.
- `[ ]` P1-PIL-06 Runbook de operación y soporte durante el piloto; proceso de incidencias.
- `[ ]` P1-PIL-07 Retro del piloto y decisión go/no-go para expandir.

---

# FASE 2 — Prometer

Meta: cupos, promesa de entrega a los canales, conector SAP, recepción en nodos completa, recojo en
tienda y consolidación entre departamentos.

## P2-CAP — `logistics/capacity` (cupos) — package nuevo

- `[ ]` P2-CAP-01 Crear package `capacity` (4 capas, registrar en providers/dont-discover).
- `[ ]` P2-CAP-02 Domain: **unidades de capacidad** y cupos por nodo/zona/fecha/franja.
- `[ ]` P2-CAP-03 Reglas de cupo **configurables y versionadas** (`settings`): cortes, fechas, horarios, días.
- `[ ]` P2-CAP-04 Consumo y liberación de cupo al planificar / reprogramar / fallar.
- `[ ]` P2-CAP-05 Presentation: Filament de cupos y calendario; tests; PHPStan 8; Pint; `openapi`.

## P2-PRM — `logistics/promise` (promesa de entrega) — package nuevo

- `[ ]` P2-PRM-01 Crear package `promise`.
- `[ ]` P2-PRM-02 Domain: cálculo de **promesa** (fechas/franjas disponibles) a partir de cobertura + capacidad + corredor.
- `[ ]` P2-PRM-03 API `/api/v1/channels/*` de **promesa para canales** (app de clientes, web, call center).
- `[ ]` P2-PRM-04 Reserva temporal de cupo al prometer; confirmación/expiración.
- `[ ]` P2-PRM-05 Presentation + tests + PHPStan 8 + Pint + `openapi`.

## P2-SAP — `logistics/sap-connector` (anti-corrupción) — package nuevo

- `[ ]` P2-SAP-01 Crear package `sap-connector` (único que conoce SAP; ADR 002).
- `[ ]` P2-SAP-02 **Lectura de pedidos** de SAP → modelo propio (traducción bulto↔material, centro, almacén).
- `[ ]` P2-SAP-03 **Traspasos de stock** al recibir en nodo: evento → outbox → SAP, **idempotente por `event_id`**, con reintentos.
- `[ ]` P2-SAP-04 **Panel de reconciliación** de traspasos pendientes/fallidos (Filament).
- `[ ]` P2-SAP-05 Tolerancia a caídas de SAP (el escaneo nunca espera a SAP).
- `[ ]` P2-SAP-06 Mapeo configurable centro/almacén; tests (incl. idempotencia y fallo de SAP); PHPStan 8; Pint; `openapi` de `/integrations`.

## P2-CAR — `logistics/carriers` (transportistas terceros) — package nuevo

- `[ ]` P2-CAR-01 Crear package `carriers`.
- `[ ]` P2-CAR-02 Domain: transportistas, **tarifas por corredor**, calificación/desempeño.
- `[ ]` P2-CAR-03 Asignación a corredor por volumen (tráiler / camión / tercero) para consolidación entre departamentos.
- `[ ]` P2-CAR-04 API `/integrations` para transportistas (estados, documentos, liquidación).
- `[ ]` P2-CAR-05 Presentation + tests + PHPStan 8 + Pint + `openapi`.

## P2-NET2 — Red completa y consolidación (extiende Fase 1)

- `[ ]` P2-NET2-01 **Recepción en nodos** completa para todos los corredores (CD→CD, CD→tienda, tienda→tienda, tienda→CD).
- `[ ]` P2-NET2-02 **Recojo en tienda** (reserva, aviso, verificación de retiro).
- `[ ]` P2-NET2-03 **Consolidación por corredor** entre departamentos en `planning` (agrupar envíos por tramo/volumen).
- `[ ]` P2-NET2-04 Lockers / agentes como tipos de nodo nuevos (si entran en alcance).
- `[ ]` P2-NET2-05 Traspasos inter-nodo con su POD y su evento de recepción.

## P2-INT — Promesa integrada con la operación

- `[ ]` P2-INT-01 Flujo completo: canal pide promesa → reserva cupo → pedido entra por `shipments` → planifica → ejecuta.
- `[ ]` P2-INT-02 Conectar fuentes de pedidos reales (SAP vía conector; Magento) a producción.
- `[ ]` P2-INT-03 Reconciliación end-to-end SAP (traspasos) monitoreada y alertada.

---

# FASE 3 — Optimizar

Meta: flota de menor costo, pronóstico, simulador y rentabilidad por entrega.

## P3-OPT — Optimización de flota y costos

- `[ ]` P3-OPT-01 Modelo de **costos** por entrega/corredor/vehículo/tercero.
- `[ ]` P3-OPT-02 Optimizador de **flota de menor costo** (propio vs tercero, llenado, corredores).
- `[ ]` P3-OPT-03 Afinar motor de rutas con restricciones de costo y ventanas.

## P3-FCT — Pronóstico

- `[ ]` P3-FCT-01 **Pronóstico de demanda** por zona/corredor/fecha a partir del histórico de eventos.
- `[ ]` P3-FCT-02 Insumo del pronóstico a capacidad/cupos y a planificación.

## P3-SIM — Simulador

- `[ ]` P3-SIM-01 **Simulador** de escenarios (flota, cupos, coberturas) sobre datos reales.
- `[ ]` P3-SIM-02 Comparar políticas (costo, cumplimiento de promesa, SLA) antes de aplicarlas.

## P3-PROF — Rentabilidad

- `[ ]` P3-PROF-01 **Rentabilidad por entrega** y por corredor (ingreso vs costo real).
- `[ ]` P3-PROF-02 Tableros de rentabilidad y alertas de corredores no rentables.

## P3-INTEL — Inteligencia sobre el flujo de eventos

- `[ ]` P3-INTEL-01 Analítica sobre el flujo inmutable de eventos (calidad de coordenadas, puntualidad, fallos).
- `[ ]` P3-INTEL-02 Mejora continua del score de confianza de direcciones con datos de entregas.
- `[ ]` P3-INTEL-03 Conectar **dis-commerce** como un adaptador más de pedidos (post-MVP, ~6 meses).

---

# Workstreams continuos (todas las fases)

- `[ ]` CONT-01 Mantener `openapi/v1.yaml` sincronizado en cada cambio de endpoint (regla no negociable).
- `[ ]` CONT-02 Mantener el vector de prueba de etiquetas idéntico en ambos repos si cambia el formato (`v`).
- `[ ]` CONT-03 Nuevas ADRs ante cada decisión de arquitectura (outbox compartido, dis-commerce, lockers, etc.).
- `[ ]` CONT-04 Deuda técnica y refactors con los límites entre packages intactos (nadie importa Infrastructure de otro context).
- `[ ]` CONT-05 Documentación viva (`docs/`) y READMEs de package al día con el estado real.
- `[ ]` CONT-06 Revisión periódica de costos (FCM, SMS, geocodificación, Route Optimization).
- `[ ]` CONT-07 Seguridad continua (rotación de claves, dependencias, secret scanning, revisión de accesos).
- `[ ]` CONT-08 Gestión de versiones de la app (siempre hay choferes con versión vieja): compatibilidad `/v1` y migración a `/v2` solo con cambio incompatible.

---

# Definición de Terminado (DoD) — aplica a cada ítem de código

Un ítem no está "hecho" hasta que (reglas de `CLAUDE.md`):

1. **Tests verdes** (Unit sin Laravel + Feature contra PostgreSQL real), reportando números (tests y aserciones).
2. **PHPStan nivel 8 limpio**.
3. **Pint limpio**.
4. **Verificación real contra los containers** (no solo tests).
5. Si agrega endpoint: **`openapi/v1.yaml` actualizado en el mismo cambio**.
6. Si es package nuevo: registrado en `bootstrap/providers.php` **y** en `extra.laravel.dont-discover`.
7. Texto visible del admin **en inglés**; comentarios y docs **en español**; `Select::make(...)->native(false)`.
8. Si cachea dominio: se cachea su **forma primitiva** (array), nunca el objeto serializado.
9. Tests sobre `logistics_test` (nunca la base de desarrollo); `TestDatabaseIsolationTest` verde.

---

# Resumen por fase (conteo aproximado de frentes)

| Fase | Foco | Packages nuevos | Grandes entregables |
| --- | --- | --- | --- |
| 0 — Discovery | Línea base y materia prima | — | KPIs base, calidad de coordenadas, flota real, acceso Firebase |
| 1 — Operar (MVP) | CD→cliente, "qué sale, en qué camión, con quién, y el cliente lo ve" | outbox, settings (base) + completar 8 contexts | API 3 puertas, app Flutter, tablero React, tracking público, notificaciones, piloto |
| 2 — Prometer | Promesa, cupos, SAP, red completa | capacity, promise, sap-connector, carriers | Promesa a canales, conector SAP con reconciliación, recojo en tienda, consolidación |
| 3 — Optimizar | Costo, pronóstico, simulador, rentabilidad | — | Optimizador de flota, pronóstico, simulador, rentabilidad por entrega |

> Este roadmap es un documento vivo: marcá casillas y abrí issues/PRs citando el ID del ítem
> (p. ej. `P1-SHP-05`). El criterio de avance real es la DoD, no la casilla.
