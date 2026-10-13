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
  read models `PlanView`/`PlannedStopView`. Los handlers de escritura liberan eventos con `// TODO(outbox)`.
- **Infrastructure**: `GoogleRouteOptimizerAdapter` (STUB: mismo orden + ETA secuencial, integración real
  a futuro), persistencia Eloquent (`PlanRecord`/`PlannedStopRecord` + `PlanMapper` + `EloquentPlanRepository`),
  migraciones de `planning_plans` y `planning_plan_stops`, y `PlanningServiceProvider` cableado.
- **Presentation**: sin controllers/Filament todavía.

Referencia a otros contextos (vehículo, envío) SOLO por id string; no ejecuta la ruta.

### Pendiente (no es "terminado" según CLAUDE.md)

Falta verificación real contra los containers (Docker no estaba disponible): `pest`, `phpstan` nivel 8 y
`pint`. Solo se dejó `php -l` limpio. Falta también tests de integración (Feature) contra PostgreSQL,
registrar el package en `bootstrap/providers.php` y en `extra.laravel.dont-discover` del composer raíz
(fuera del alcance de este cambio), e integrar el outbox para despachar los eventos de dominio.
