---
name: fleetops-context
description: "El bounded context fleetops: operación y costos de la FLOTA PROPIA (combustible, mantenimiento, salidas, odómetro, perfil, gastos, TCO)"
metadata:
  node_type: memory
  type: project
  originSessionId: 95be9a11-8872-4d3d-b8b2-ef0548264b66
  modified: 2026-10-14T00:22:25.454Z
---

Contexto `packages/logistics/fleetops` (namespace `Logistics\FleetOps\`), construido el 2026-10-14 con
agentes en paralelo (4 núcleo por grupo de agregado + 4 presentación/tests). DDD+CQRS+Clean, UUID v7,
outbox, dinero en centavos (VO `Money` BOB, replicado del de carriers). Es el centro de control de la
**flota propia** — complementa `fleet` (maestros: placa/capacidad/documentos) y NO se solapa con `carriers`
(terceros). `vehicle_id` string = UUID de `fleet_vehicles` (desacople). Km/consumo se leen de `telemetry`.

**Qué da (lo que pidió el dueño):** precios de combustible por fecha/tipo (diésel/especial/premium/GNV);
cargas de combustible (litros, Bs/litro, odómetro) → **gasto diario** y **consumo km/l** (tanque-lleno);
**mantenimiento** (órdenes preventivo/correctivo con taller+items+costo; planes por km/días con alerta de
vencimiento); **salidas/despachos** (chofer, tripulación, **carga** peso/ítems, odómetro salida/retorno,
horas, km); **odómetro** (lecturas); **perfil técnico** del vehículo (marca/modelo/año/combustible/tanque/
odómetro — lo que fleet no tiene); **gastos** (peajes/multas/llantas…) → **TCO** y **costo por km**.

**4 grupos / 8 agregados:** A Combustible (`FuelPrice`, `Refuel`); B Mantenimiento (`MaintenanceOrder`,
`MaintenancePlan`); C Salidas+Odómetro (`Departure`, `OdometerReading`); D Perfil+Gastos (`VehicleProfile`
PK=vehicle_id, `Expense`). VOs compartidos: `Money`, `Odometer`, `FuelType`, `FleetOpsException`.
Tablas `fleetops_*` (8). Dinero en `*_cents` + `currency`.

**Piezas transversales (ensamblado por el orquestador):**
- `GetVehicleCostSummary` (query TCO): suma combustible + mantenimiento + gastos y divide por los km de
  telemetría (`DB::table('telemetry_trips')->sum('distance_m')`, guarded) → `cost_per_km_cents`.
- `DueMaintenanceScanner` + `ListDueMaintenance` (query) + `AlertDueMaintenance` (command+console
  `logistics:fleetops:maintenance-alerts`, scheduler diario 07:30) → emite `FleetMaintenanceDueAlerted` al
  outbox ('fleetops.maintenance_due') → notificación al supervisor. **Patrón "escanear+alertar al outbox"
  copiado de fleet/AlertExpiringDocuments.**

**API:** puerta `app` = gestión+lecturas (37 rutas: fuel-prices/refuels/consumption/daily-spend;
maintenance orders+plans+due+cost; departures+close; odometer+utilization; vehicle-profiles; expenses;
cost-summary). Puerta `integrations` = `POST fleetops/refuels` (tarjeta/estación) y `POST fleetops/odometer`.
OpenAPI aditivo (29 paths). **Admin Filament** (grupo `Fleet Ops`): 8 Resources + página `FleetCostDashboard`
(TCO por vehículo + mantenimiento vencido). Placa resuelta leyendo `fleet_vehicles` (modelo real = `VehicleModel`,
NO `VehicleRecord`) en lote (helper/trait, read-only cross-context).

Registrado en: `composer.json` raíz, `bootstrap/providers.php` (tras Fleet), `AdminPanelProvider`
(NavigationGroup Fleet Ops + discoverResources + FleetCostDashboard en `->pages([])`), `routes/api.php`
(require), `DatabaseSeeder` (`FleetOpsDemoSeeder` tras FleetSeeder; idempotente, lee vehicle_ids reales).

**DoD VERDE (2026-10-14):** PHPStan 8 limpio (módulo y repo global 0), Pint PASS (2330 files), Pest módulo
**63 passed/190 aserciones**, Filament resuelve (8 resources + dashboard), verificación en vivo end-to-end
(flujo completo → TCO 835 BOB, costo/km, 9 eventos outbox). Ver [[project-architecture]], [[telemetry-context]],
[[fleet-personnel-expansion]], [[api-readmodel-enrichment]], [[no-destructive-db-ops]].
