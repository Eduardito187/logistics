---
name: routing-geo-rule
description: Regla de negocio — alcance geográfico de rutas/entregas de camiones y motor de ruteo
metadata:
  node_type: memory
  type: project
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-13T19:58:11.297Z
---

**Regla dura (invariante de dominio) para rutas/entregas de camiones:**
- Una ruta/entrega a **clientes** debe ser del **mismo municipio**, o si es **intermunicipal**, del **mismo departamento**.
- **NO existe** una entrega a clientes **multidepartamental**.
- **Excepción:** traspasos **CD→CD** (centro de distribución a centro de distribución) sí pueden cruzar departamentos — pero en esos casos **no hay clientes** (es nodo→nodo, no entrega a cliente).

Implica: al asignar envíos a un camión / generar plan de reparto / armar la ruta, validar que todas las paradas de cliente compartan departamento (idealmente marcar si es intermunicipal). El departamento de una parada sale de `coverage` (jerarquía geográfica: la zona/área de la coordenada → su departamento). Los legs CD→CD quedan exentos de la regla de cliente.

**Orden de paradas (regla):** la ruta debe asignarse/ordenar por **cercanía** (vecino más cercano / optimización tipo TSP), nunca ida-y-vuelta absurda (ej. La Paz→Santa Cruz→La Paz). OSRM `/trip` da el orden óptimo; si OSRM no responde, usar un fallback nearest-neighbor en el RouteOptimizer (no dejar el orden de entrada crudo).

**Incidentes del camión (accidente/avería) — debe estar contemplado:** si el camión se accidenta o descompone, el chofer **alerta** (desde la app/admin) y el sistema reacciona automáticamente:
- **Grave:** las paradas pendientes se **reprograman para el día siguiente** (replanificación) y el vehículo queda fuera de servicio.
- **Leve:** reprogramar con retraso (empujar ETAs) o reasignar a otro camión.
Emite eventos de dominio (incidente, reprogramación) → notificaciones al supervisor y a los clientes afectados ("pedido reprogramado"). Involucra execution (reporte en la ruta), planning (reprogramar/replanificar), fleet (vehículo fuera de servicio), notifications.

**Motor de ruteo (implementado al 2026-10-13):** Leaflet + OpenStreetMap para el dibujo; OSRM público (`https://router.project-osrm.org`, env `OSRM_HOST`) alcanzable desde el server (`/route`, `/table`, `/trip`, `/match`).
- **Dos problemas distintos**: (1) ORDEN/ASIGNACIÓN de paradas = TSP/VRP (NO es Dijkstra); (2) trazo punto-a-punto por calles = OSRM, que usa Contraction Hierarchies (mejor que Dijkstra puro). Dijkstra solo resuelve camino-más-corto entre 2 puntos; no sirve para ordenar paradas ni asignar camiones.
- **VRP real** en planning: `ClarkeWrightSolver` (ahorros) para asignar paradas a camiones por capacidad + mismo-departamento, y `RouteSequencer` (vecino-más-cercano + 2-opt + Or-opt) sobre la **matriz real de OSRM `/table`** (cacheada en Valkey). Contrato `RoutingBackend` **enchufable** por `logistics-planning.routing.backend`: `builtin` (Clarke-Wright+2-opt, DEFAULT), `osrm_trip`, `vroom` (usa `VROOM_URL` si está; si no, cae a builtin). VROOM necesitaría self-host OSRM-Bolivia (por eso builtin es el default).
- **Geometría server-side**: al planificar se calcula OSRM `/route` (geojson) y se persiste en `planning_plans.route_geometry` (+distance/duration). El mapa la pinta sin depender del navegador; fallback a OSRM en navegador y luego línea recta.
- **Auto-planner** (planning): `GenerateDailyPlan(date, strategy)` con 2 modos — `optimization` (capacidad+cercanía) y `slots` (respeta la franja elegida por el cliente). **Asíncrono por colas** (job idempotente por día+modo, chunk, eventos al outbox). Acción Filament "Auto-plan the day" + API `/api/v1/app/plans/auto`.
- **Slots/calendario de despacho** (capacity+promise+shipments): el pedido lleva `delivery_date`/`delivery_window_*`; API de canales lista y reserva slots (cupo atómico `SELECT FOR UPDATE`, idempotente); página Filament "Dispatch calendar". Config en `logistics-capacity.dispatch_calendar`.

Ver [[project-architecture]], [[admin-ui-maps]], [[brand-palette]].
