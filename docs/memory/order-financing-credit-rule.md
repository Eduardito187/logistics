---
name: order-financing-credit-rule
description: "Regla de negocio: paso a procesando en órdenes a crédito/minicuotas (contrato + inicial), vía FinancingPlan + señales por API; README con todos los comandos"
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-11T08:43:31.624Z
---

**Regla de crédito/minicuotas (2026-11-11).** Cuándo una orden pasa a «procesando» (OrderPaid → crea envío →
tracking procesando) según su financiamiento:
- **Contado** (`cash`): pago capturado (`payments.captured` → `markPaid`) — como siempre.
- **Crédito SIN inicial** (`credit_without_initial`): solo con la **firma de contrato**.
- **Crédito CON inicial** (`credit_with_initial`): firma de contrato **Y** pago del inicial (cualquier orden).

**Decisiones del dueño:** el financiamiento es un **dato de la orden al crearse**; «contrato firmado» e
«inicial pagado» llegan como **eventos externos por API** (POS/crédito); la plataforma **NO modela cuotas**
(saldo vive en el POS); al cumplirse se **reusa `OrderPaid`** (no un estado nuevo). El saldo del crédito NO
bloquea el despacho.

**Implementación (`packages/commerce/orders`):**
- VO `FinancingPlan` (enum `cash|credit_with_initial|credit_without_initial`; `isCredit()`,
  `requiresInitial()`).
- Agregado `Order`: campos `financing`, `contractSigned`, `initialPaid`. `place(..., financing=Cash)`.
  `markPaid()` es **no-op para crédito** (no se cobra en full). Métodos `signContract(?ref)`,
  `recordInitialPayment(?ref)` (solo crédito; el segundo solo si requiresInitial) → `promoteIfReady()` emite
  `OrderPaid` cuando se cumplen las condiciones. Eventos nuevos `OrderContractSigned`
  (`orders.order_contract_signed`), `OrderInitialPaid` (`orders.order_initial_paid`).
- Comandos `SignOrderContract`, `RecordInitialPayment` (+ handlers, registrados en `OrdersServiceProvider`).
- API puerta **integrations**: `POST /api/v1/integrations/orders/{order}/contract-signed` y
  `/initial-paid` (controllers `SignOrderContractController`/`RecordInitialPaymentController`, body opcional
  `{reference}`, 202/404/422). `PlaceOrder`/`PlaceOrderFromCart` + `PlaceOrderRequest` ganan `financing`
  (default `cash`, enum validado).
- Persistencia: migración aditiva en `commerce_orders` (`financing` default 'cash' + index, `contract_signed`,
  `initial_paid` bool default false). `OrderRecord` casts + `OrderMapper` toRow/toDomain.
- Admin `OrderResource`: columnas Financing (badge) + Credit (contrato/inicial ✓/✗) + filtro; solo lectura.
- OpenAPI: `financing` en `CommercePlaceOrderRequest`, schema `CommerceCreditSignalRequest`, los 2 endpoints.

Downstream SIN cambios: `OrderPaid` ya dispara `OrderPaidShipmentDispatcher` (crea envío) + el reactor de
tracking «procesando» (ver [[tracking-lifecycle-state-machine]]).

**README de comandos (2026-11-11).** El README del repo tiene la sección «Comandos del proyecto (referencia)»
con TODOS los artisan (agendados / operativos / claves de etiquetas / backup-restore / retención-purga) y los
targets del Makefile — cada uno con título, para-qué y cuándo ejecutar. Fuente de los agendados:
`routes/console.php`. Relacionado con [[project-tooling]], [[db-backup-restore]].
