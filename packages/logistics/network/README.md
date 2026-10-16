# logistics/network — Red logística: nodos y tramos

## Responsabilidad

Nodos (CD, tiendas, puntos de recojo; más adelante lockers o agentes) con su ubicación, estado y departamento. Un envío es una secuencia de tramos entre nodos. Los seis corredores (`CorridorType`) son: CD→cliente, CD→CD, CD→tienda, tienda→tienda, tienda→CD y recojo en tienda.

### Corredores y tramos

- Cuatro corredores **unen dos nodos** y se modelan como tramos (`Leg`): CD→CD, CD→tienda, tienda→tienda y tienda→CD. Al definir un tramo, `Leg::define` valida que los tipos de nodo de origen y destino calcen con el corredor (p. ej. CD→tienda exige un CD de origen y una tienda de destino).
- Dos corredores son **terminales**: acaban en el cliente, no en un nodo (CD→cliente para última milla, recojo en tienda). No son tramos; los ofrece cada nodo por sí mismo.

### Corredores operativos por departamento

`OperationalCorridorsByDepartmentQuery` resuelve, para un departamento, qué corredores están operativos: los tramos cuyo nodo de origen está activo y en ese departamento (con destino activo) más los corredores terminales que cada nodo activo ofrece. Es el insumo que consumen planning y promise (vía QueryBus) y también se expone en `GET /api/v1/app/network/corridors?department=...`.

Cada nodo lleva su `Department` (uno de los nueve de Bolivia); es opcional (un nodo importado de SAP puede llegar sin él y asignarse después).

### Almacén central y andenes de recepción (inbound / marketplace)

Aditivo (roadmap marketplace §5). `NodeType::CentralWarehouse` marca un nodo como **almacén central**:
recibe mercadería de proveedores (inbound/ASN), la etiqueta y la distribuye como un CD (aguas abajo se
comporta como `DistributionCenter`: ofrece última milla). Es el único tipo con `receivesInbound() === true`.
No requiere cambio de esquema en los nodos: reutiliza la columna `type` existente; los nodos previos
quedan en su tipo actual.

El **andén de recepción** (`ReceivingDock`, agregado) es por dónde entra la mercadería del proveedor.
Cuelga de un almacén central e incluye su capacidad nominal (`DockCapacity`: citas por franja). Tabla
aditiva `receiving_docks` (`warehouse_id`, `code` único por almacén, `name`, `appointments_per_slot`,
`active`). El andén NO lleva la cuenta de cupos agendados: ese agendamiento vive en `capacity` (eje
inbound), que toma esta capacidad como tope de referencia.

- Comando `RegisterReceivingDock(warehouseId, code, name, appointmentsPerSlot)` → registra el andén
  (valida que el nodo exista y sea almacén central; rechaza códigos duplicados dentro del almacén).
- Consulta `ListReceivingDocks(warehouseId)` → `list<ReceivingDockView>`.

## No es responsable de

Decidir rutas o camiones: eso es planificación. Tampoco resuelve la jerarquía geográfica fina (provincia, municipio, ciudad, zona): eso es coverage.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Dominio y CQRS implementados; verificación en containers pendiente.
