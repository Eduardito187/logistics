# logistics/planning — Planificación

## Responsabilidad

Propuesta automática pedido→camión, secuencia, ETA, plan de carga (orden invertido y carril). Ajuste humano y publicación. Motor de rutas detrás de una interfaz propia (Google Route Optimization primero). Más adelante: consolidación por corredor entre departamentos.

## No es responsable de

Ejecutar la ruta o registrar escaneos.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Dominio, aplicación (CQRS) e infraestructura construidos con el patrón DDD + CQRS + Clean Architecture.

- **Domain**: agregado `DeliveryPlan` (borrador → propuesto → publicado) con la entidad `PlannedStop`;
  VOs `PlanDate`, `Eta`, `StopSequence`, `LoadLane`, `PlanStatus`; ids tipados `PlanId`/`PlannedStopId`;
  eventos `PlanProposed`/`PlanPublished`; contratos `PlanRepository` y `RouteOptimizer` (motor de rutas
  detrás de interfaz propia). PHP puro, sin Laravel.
- **Application**: comandos `GeneratePlanProposal`, `AssignShipmentToVehicle`, `ReorderStops`,
  `PublishPlan`; consultas `GetPlan` (→ `?PlanView`) y `ListPlansByDate` (→ `list<PlanView>`);
  read models `PlanView`/`PlannedStopView`. Los handlers de escritura publican sus eventos al outbox con
  `RecordDomainEvents::record(..., 'planning.plan')`, dentro de la transacción que abre el decorador
  transaccional del CommandBus (Infrastructure).
- **Infrastructure**: `GoogleRouteOptimizerAdapter` (STUB: mismo orden + ETA secuencial, integración real
  a futuro), persistencia Eloquent (`PlanRecord`/`PlannedStopRecord` + `PlanMapper` + `EloquentPlanRepository`),
  migraciones de `planning_plans` y `planning_plan_stops`, y `PlanningServiceProvider` cableado.
- **Presentation**: `PlanController` (puerta app) con todos los casos de uso — listar planes del día,
  generar propuesta, ver plan, ETA por parada, asignar envío, reordenar secuencia, publicar — hablando
  solo con los buses CQRS; Filament de solo lectura (`PlanResource`, `PlannedStopResource`), ya que los
  planes nacen y cambian por la API, no por el admin. Fragmento de rutas en `routes/api/planning.php`
  (puerta app) y contrato en `openapi/paths/planning.yaml`.
- **Demo / tests**: factories por modelo (`PlanRecordFactory`, `PlannedStopRecordFactory`, namespace
  `Logistics\Planning\Database\Factories`) y `PlanningDemoSeeder` (namespace
  `Logistics\Planning\Database\Seeders`), que arma planes de HOY recorriendo el camino real del dominio
  (borrador → propuesta → plan de carga → publicación) para La Paz. Ambos namespaces se autoloadean con
  `Logistics\Planning\Database\ => database/` en el `composer.json` del package.

Referencia a otros contextos (vehículo, envío) SOLO por id string; no ejecuta la ruta.

### Pendiente (no es "terminado" según CLAUDE.md)

Falta verificación real contra los containers (Docker no estaba disponible): `pest`, `phpstan` nivel 8 y
`pint`. Falta también tests de integración (Feature) contra PostgreSQL. La reintegración de los
fragmentos compartidos (montaje de `routes/api/planning.php` en `routes/api.php`, fusión de
`openapi/paths/planning.yaml` en `openapi/v1.yaml`, registro de `PlanningDemoSeeder` en el
`DatabaseSeeder` del proyecto) y el despacho real del outbox corren a cargo de los integradores.

El motor de rutas sigue siendo un **stub** (`GoogleRouteOptimizerAdapter`): devuelve las paradas en el
orden de entrada con ETA secuencial. La integración real con Google Route Optimization se enchufa detrás
de la misma interfaz `RouteOptimizer`, sin tocar dominio ni aplicación.

## Optimizador de ruteo (VRP) + geometría server-side

El núcleo de ruteo es un VRP real sobre la red de calles, no línea recta, y es ENCHUFABLE por configuración
(`logistics-planning.routing.backend`):

- **`builtin` (DEFAULT)**: `BuiltinRoutingBackend`. Parte los pedidos por departamento (y franja en modo
  slots), arma la matriz real por carretera del grupo, asigna paradas a camiones con **Clarke-Wright
  (savings)** respetando capacidad (kg/m3) y la regla "un camión = un departamento", y ordena cada ruta con
  **vecino más cercano + 2-opt/Or-opt** (`RouteSequencer`). Determinista.
- **`osrm_trip`**: `OsrmTripRoutingBackend`. Comportamiento anterior (empaque greedy `DailyPlanComposer` +
  orden por OSRM `/trip` por camión), más la geometría server-side.
- **`vroom`**: `VroomRoutingBackend`. Delega el VRP a un servidor VROOM si `VROOM_URL` está configurado;
  si no, o ante cualquier fallo, **cae a `builtin`**.

Piezas clave:

- **Matriz real** (`RoadMatrixProvider` → `OsrmRoadMatrixProvider`): distancias/tiempos por carretera vía
  OSRM `/table` (`annotations=distance,duration`), **cacheada en Valkey por conjunto de coordenadas**
  (forma primitiva, no el objeto — regla 7), **en lotes** (submatrices) para cientos de paradas, con
  **degradación a haversine** si OSRM falla.
- **Algoritmos de dominio PHP puro**: `ClarkeWrightSolver`, `RouteSequencer` (NN + 2-opt + Or-opt),
  `DepartmentRouteGrouping`; contratos `RoutingBackend`, `RoadMatrixProvider`, `RouteGeometryProvider`,
  `PlanningOriginLocator`; VOs `GeoPoint`, `DistanceMatrix`, `PlannedRoute`, `RoutingResult`, `RouteGeometry`.
- **Geometría server-side** (`RouteGeometryProvider` → `OsrmRouteGeometryProvider`): al generar el plan se
  calcula la polilínea por calles (OSRM `/route`, `overview=full`, `geometries=geojson`) y se **persiste**
  en el plan (columna jsonb `route_geometry` + `route_distance_meters`/`route_duration_seconds`), para que
  el mapa la pinte sin depender del navegador. Se expone en `PlanView` (`route_geometry`) y en el contrato
  OpenAPI (`Plan.route_geometry` → `RouteGeometry`). Reordenar manualmente las paradas invalida la geometría.

Config/env: `OSRM_HOST`, `OSRM_TIMEOUT`, `PLANNING_ROUTING_BACKEND`, `VROOM_URL`,
`OSRM_TABLE_MAX_COORDINATES`, `PLANNING_MATRIX_CACHE_TTL`, `PLANNING_FALLBACK_SPEED_KMH`.
