# logistics/inbound

Contexto **Inbound** (Fase 2 del roadmap marketplace, ADR 017): recepción de mercadería de sellers en
un almacén central de Dismac. Es el caso **FBD (Fulfilled by Dismac)** de punta a punta aguas arriba del
**punto de convergencia**: anuncio (ASN) → cita → llegada → recepción con control de calidad (QC) →
etiquetado. A partir del etiquetado la unidad es operacionalmente idéntica a una Dismac y toda la
distribución existente (planning/execution/telemetry/última milla) se reutiliza sin cambios.

## Responsabilidad

- Agregado `Asn` (Advanced Shipping Notice / cita): seller, almacén destino, líneas (sku del seller +
  cantidad esperada), ventana de cita y ciclo de vida
  `Announced → Scheduled → Arrived → Receiving → Received → Closed` (o `Cancelled`).
- Agregado `GoodsReceipt`: lo realmente recibido por línea con QC (`Accepted | Rejected | PartialDamage`)
  y el etiquetado de las unidades aceptadas.
- CQRS: escritura por comandos, lectura por consultas con ReadModels; todo evento sale por el **outbox**.

## NO es responsabilidad de este contexto

- Crear la unidad física etiquetada ni emitir el QR: eso vive en `shipments`/`labels`. La **convergencia**
  se modela emitiendo `inbound.goods_received` / `inbound.units_labeled` con los datos necesarios (seller,
  sku, cantidades, ownership FBD); un **subscriber del orquestador** engancha el resto. NO hay dependencia
  composer a `shipments` ni a `labels` (se mantiene extraíble a microservicio — ADR 001/008).
- Los cupos de andén los administra `capacity`. `inbound` solo **consume** un cupo de recepción al agendar,
  detrás del puerto `Domain\Contract\DockQuotaReserver` (adaptador por defecto: despacha
  `ConsumeCapacityCommand` de capacity por el `CommandBus`). Si capacity aún no expone el eje de recepción,
  el puerto permite un doble de prueba y el flujo núcleo (announce → arrive → receipt → label) sigue testeable.

## Estado

Fase 2. Hito de negocio: **FBD operativo** reutilizando toda la distribución existente.
