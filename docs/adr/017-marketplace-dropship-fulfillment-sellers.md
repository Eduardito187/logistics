# ADR 017 — Marketplace / Dropship / Fulfillment por sellers

**Status:** Accepted
**Fecha:** 2026-10-16
**Autores:** @eduard
**Deciders:** @eduard (dueño del proyecto)

## Contexto

Toda la plataforma asume producto **retail**: Dismac es el dueño del inventario. No existe ninguna noción
de **seller/proveedor**, **propiedad del inventario**, **consignación** ni **recepción de proveedor
(inbound/ASN)** — verificado: 0 conceptos de `seller/marketplace/dropship/consignment/inbound/owner` en
`packages/logistics/*/src/Domain`.

El negocio necesita operar producto que **no es de Dismac**:

- **Marketplace / FBD (Fulfilled by Dismac):** el seller lleva su producto a un almacén central; Dismac lo
  recibe, lo etiqueta, lo almacena y lo distribuye igual que un producto propio.
- **Dropship:** el seller despacha directamente; Dismac coordina y/o etiqueta, o solo da visibilidad.

Fuerza clave (punto de convergencia): una vez que una unidad está **recibida y etiquetada** en un almacén
central, es **operacionalmente idéntica** a un producto Dismac. Todo lo de aguas abajo (planificar, rutear,
última milla, telemetría, transferencias entre nodos) **ya existe y se reutiliza**. Lo nuevo está aguas
arriba (entrada del seller + propiedad) y en unas pocas etiquetas transversales (liquidación, devoluciones,
SAP).

## Decisión

Se introduce una **dimensión de propiedad/fulfillment** transversal y aditiva, más tres a cuatro bounded
contexts nuevos, sin reescribir nada de lo existente (todo queda `OWNED` por defecto):

1. **VO transversal `Ownership` + enum `FulfillmentMode`** (`OWNED | FBD | DROPSHIP`) en `logistics/shared`,
   que viaja con el envío y la unidad física (`owner_party_id` nullable + `fulfillment_mode`). `OWNED` ⇒
   dueño null; `FBD/DROPSHIP` ⇒ dueño no null. El dueño se guarda como `Uuid` genérico (shared NO depende
   de ningún contexto); el contexto `sellers` lo interpreta.
2. **Contexto `sellers`** (party de primera clase: alta, contrato, términos de liquidación, catálogo de SKUs).
3. **Contexto `inbound`** (ASN/cita, cupos de andén reutilizando `capacity`, goods receipt + QC + put-away
   que emite unidades etiquetadas reutilizando `labels`).
4. **Contexto `inventory`** (stock con atribución de dueño, consignación vs. propio, segregación lógica).
5. **Contexto `settlement` de sellers** (liquidación por despacho menos fees; clona el patrón `carriers/Settlement`).

Se construye con la arquitectura objetivo (ADR 001/008): DDD + CQRS + Clean Architecture, UUID v7, outbox
transaccional, cada contexto autónomo y **extraíble a microservicio** (`inbound` es el primer candidato).
La frontera con SAP (ADR 002) no cambia; solo se añade la **dimensión de propiedad** para no mezclar
titularidad contable (el stock de un seller nunca se postea como material propio de Dismac).

El plan de construcción **punto por punto, por fases**, vive en
[`docs/marketplace-dropship-roadmap.md`](../marketplace-dropship-roadmap.md). Fases: (1) fundación
`Ownership` + `sellers`; (2) `inbound` → FBD de punta a punta; (3) `inventory` + SAP consignación;
(4) `settlement` + logística inversa; (5) dropship puro + portal/API del seller.

## Alternativas consideradas

### Alternativa A: tratar al seller como un "cliente/proveedor" más dentro de `shipments`, sin contextos nuevos
- Pros: menos packages; cambio aparentemente más pequeño.
- Contras: mezcla responsabilidades (inventario de terceros, liquidación, recepción) en un contexto de
  envíos; no escala; rompe Clean Architecture; imposible de extraer a microservicio después.

### Alternativa B: un único contexto "marketplace" monolítico que haga todo (sellers + inbound + inventario + liquidación)
- Pros: un solo package.
- Contras: agregado-dios; viola "un bounded context por responsabilidad"; el `inbound` (que más escala
  horizontal pide) quedaría acoplado a la liquidación. No permite extraer solo lo que el volumen exige.

### Alternativa C (elegida): dimensión de propiedad transversal + contextos separados por responsabilidad
- Pros: aditivo (retail intacto); cada contexto autónomo y extraíble; reutiliza toda la distribución ya
  construida; el 70% del valor sale en las fases 1–2.
- Contras: más packages que gestionar; requiere disciplina en los campos aditivos y en la frontera SAP.

## Consecuencias

### Positivas
- Habilita marketplace/FBD y dropship reutilizando planning/execution/telemetry/última milla sin cambios.
- Mantiene retail 100% intacto (`OWNED` por defecto).
- `inbound` extraíble a microservicio cuando el volumen de recepción lo pida.

### Negativas / trade-offs aceptados
- 3–4 packages nuevos + campos aditivos en `shipments`/`network`/`capacity`/`sap-connector`/`promise`.
- La contabilidad de inventario por dueño (consignación) añade complejidad real al sap-connector.

### Neutras
- El punto de convergencia (`labels`) no cambia: una unidad etiquetada es uniforme desde ahí.

## Referencias

- [`docs/marketplace-dropship-roadmap.md`](../marketplace-dropship-roadmap.md) — plan punto por punto por fase.
- ADR 001 (monolito modular), ADR 002 (fuera de SAP), ADR 008 (DDD/CQRS/UUIDv7/outbox).
- Skill de proyecto `.claude/skills/bounded-context`.
