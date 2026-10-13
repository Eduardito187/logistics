# 04 — Inventario de flota y nodos reales

> Fase 0 (Discovery). Cubre **F0-FLEET-01** (vehículos), **F0-FLEET-02** (choferes) y
> **F0-FLEET-03** (nodos: CDs, tiendas, puntos de recojo). Objetivo: tener la materia prima real
> con la que luego se poblarán los contextos `fleet` y `network`, sin inventar nada.

El relevamiento se hace en tres planillas CSV bajo `docs/discovery/templates/`. Cada columna mapea
a un campo real del dominio, para que la carga posterior (seeders / importadores) sea mecánica:

- `templates/fleet-inventory.csv` → agregado `Vehicle` de `packages/logistics/fleet`.
- `templates/drivers-inventory.csv` → agregado `Driver` de `packages/logistics/fleet`.
- `templates/nodes-inventory.csv` → agregado `Node` de `packages/logistics/network`.

## Principios del relevamiento

- **Una fuente de verdad por entidad.** No duplicar un vehículo o un chofer en dos filas de identidad
  distintas. Para vehículos, la excepción es documental: una fila por documento con vencimiento
  (ver abajo).
- **Datos reales, no aspiracionales.** Si una capacidad no está medida, estimarla y marcarlo en
  `notes`; no dejar el número "bonito".
- **Códigos estables.** El `home_base_node_code` de un vehículo y el `code` de un nodo son la clave
  humana que une las planillas entre sí. Acordar el catálogo de códigos de nodo **antes** de llenar
  la planilla de flota.
- **UTF-8 sin tildes en claves técnicas.** Los códigos (placas, códigos de nodo, licencias) van en
  mayúsculas, sin espacios ni `|`; el texto libre (`notes`, `name`, `capacity_notes`) puede llevar
  tildes.

## Cómo relevar la flota (`fleet-inventory.csv`)

Cabecera: `plate,capacity_kg,capacity_m3,home_base_node_code,status,doc_type,doc_expires_on,notes`

| Columna | Mapea a | Cómo llenarla |
| --- | --- | --- |
| `plate` | `PlateNumber` | Placa física del camión, mayúsculas. Formato boliviano amplio (letras, dígitos y guion opcional). Es la clave: se repite en cada fila de documento del mismo vehículo. |
| `capacity_kg` | `Capacity::kilograms` | Peso máximo en **kilogramos enteros** (sin fracciones de kilo). |
| `capacity_m3` | `Capacity::cubicMeters` | Volumen máximo en **m³**, hasta tres decimales (al litro). |
| `home_base_node_code` | `Vehicle::homeBaseNodeId` (vía código de nodo) | Código del CD base. Debe existir en `nodes-inventory.csv`. |
| `status` | `VehicleStatus` | Uno de: `available`, `in_service`, `maintenance`, `inactive`. |
| `doc_type` | `DocumentExpiry::type` | Tipo de documento: `soat`, `inspeccion_tecnica`, `extintor`, etc. Texto libre en minúsculas. |
| `doc_expires_on` | `DocumentExpiry::expiresOn` | Fecha de vencimiento `YYYY-MM-DD`. |
| `notes` | — | Texto libre: tipo de camión, observaciones, origen del dato. |

**Modelo de documentos (importante).** Un vehículo tiene *varios* documentos con vencimiento
(SOAT, inspección técnica, extintor…). Como un CSV es plano, se usa **una fila por documento** y se
**repite la placa y el resto de columnas de identidad** en cada fila. El importador agrupa por
`plate`: la primera fila define el vehículo, las demás solo agregan documentos. Un vehículo sin
documentos relevados va en una sola fila con `doc_type` y `doc_expires_on` vacíos.

## Cómo relevar choferes (`drivers-inventory.csv`)

Cabecera: `full_name,license_number,license_expiry,enabled_vehicle_plates,active,notes`

| Columna | Mapea a | Cómo llenarla |
| --- | --- | --- |
| `full_name` | `Driver::name` | Nombre completo del chofer. |
| `license_number` | `LicenseNumber` | Número de licencia, mayúsculas, alfanumérico (admite guion). |
| `license_expiry` | `Driver::licenseExpiry` (`DocumentExpiry`) | Vencimiento de la licencia `YYYY-MM-DD`. |
| `enabled_vehicle_plates` | `Driver::enabledVehicleIds` | Placas que el chofer está habilitado a conducir, **separadas por `;`**. Cada placa debe existir en `fleet-inventory.csv`. |
| `active` | `Driver::active` | `true` / `false`. |
| `notes` | — | Texto libre: categoría de licencia, ayudante, observaciones. |

> La licencia se mapea por placa en la planilla (clave humana); el importador la resuelve al
> `VehicleId` real al cargar. Un chofer solo puede conducir los vehículos para los que está
> habilitado (regla del dominio `Driver::isEnabledFor`).

## Cómo relevar nodos (`nodes-inventory.csv`)

Cabecera: `code,name,type,lat,lng,department,capacity_notes`

| Columna | Mapea a | Cómo llenarla |
| --- | --- | --- |
| `code` | `NodeCode` | Código corto y único, mayúsculas, con guiones (p. ej. `CD-LP-01`, `T-SCZ-03`, `PR-CBB-02`). Longitud 3..20. Es el código que viaja en la etiqueta QR. |
| `name` | `Node::name` | Nombre legible del nodo. |
| `type` | `NodeType` | Uno de: `distribution_center`, `store`, `pickup_point`. |
| `lat` | `GeoPoint::latitude` | Latitud WGS 84 (-90..90). Bolivia es negativa. |
| `lng` | `GeoPoint::longitude` | Longitud WGS 84 (-180..180). Bolivia es negativa. |
| `department` | — (metadato de discovery) | Departamento (La Paz, Santa Cruz, Cochabamba…). Insumo para F0-FLEET-04 (corredores). |
| `capacity_notes` | — (metadato de discovery) | Texto libre sobre capacidad física: m², andenes, zona de staging. El dominio `Node` todavía no modela capacidad numérica; se releva como nota para dimensionar y, si hace falta, modelarla después. |

## Cómo se cargará después a los contextos

1. **network primero.** Los nodos son la base: el `home_base_node_code` de la flota los referencia.
   Se cargan con un seeder/importador idempotente que, por cada fila, arma
   `Node::register(NodeId, NodeCode::fromString(code), name, NodeType::from(type), GeoPoint::of(lat,lng))`.
   La columna PostGIS la puebla la infraestructura a partir de `lat`/`lng` (ver P1-NET-04/08).
2. **fleet después.** Por cada `plate` agrupada, `Vehicle::register(...)` con su `Capacity`, el
   `homeBaseNodeId` resuelto desde el `home_base_node_code`, el `VehicleStatus` y la lista de
   `DocumentExpiry` (una por fila de documento). Luego `Driver::register(...)` resolviendo
   `enabled_vehicle_plates` a los `VehicleId` reales.
3. **Idempotencia.** El importador usa la clave humana (`code`, `plate`, `license_number`) para no
   duplicar en recargas. Volver a correrlo con la misma planilla no crea registros nuevos.

Este trabajo de seeders/importadores es de Fase 1 (ver `P1-NET-08` y la DoD de `fleet`); en Fase 0
solo se producen las planillas limpias.

## Alerta: documentos vencidos y por vencer

El dominio ya sabe alertar documentos por vencer (`Vehicle::documentsExpiringWithin`,
`Driver::isLicenseValidAt`, `DocumentExpiry::expiresWithin` — incluye los ya vencidos). Durante el
relevamiento, **marcar en rojo** todo documento con `doc_expires_on` o `license_expiry` anterior o
cercano a la fecha de hoy:

- Un vehículo con SOAT o inspección técnica vencida **no debe salir a ruta**: ponerlo en
  `maintenance` o `inactive` y anotarlo en `notes`.
- Un chofer con licencia vencida va con `active=false` hasta regularizar.
- Consolidar al final del relevamiento una lista de "documentos vencidos / por vencer en 30 días"
  como entregable de F0-FLEET; es el primer backlog de regularización de la operación.

## Verificación de las planillas

- CSV coma-separado, UTF-8, con la cabecera exacta de arriba (no renombrar columnas).
- Sin filas vacías intermedias ni comas de más.
- Toda placa referenciada en choferes existe en la planilla de flota; todo `home_base_node_code`
  existe en la de nodos.
- Fechas en formato `YYYY-MM-DD`.
