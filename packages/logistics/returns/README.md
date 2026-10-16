# Returns — logística inversa (devoluciones)

Bounded context de la **Fase 4** del roadmap marketplace (ADR 017, §7.4): cierra el ciclo físico de un
producto que vuelve. Modela la devolución **de cliente** (`CustomerReturn`) y la devolución **al proveedor**
(`ReturnToVendor` / RTV), su recepción en un almacén central y su **disposición**: `Restock` (vuelve al
stock, al lote del dueño correcto), `ReturnToSeller` (se devuelve al seller) o `Scrap` (se descarta).

## Responsabilidad

- Custodiar el ciclo de vida de una devolución: `Initiated → Received → Dispositioned → Closed`.
- Emitir los hechos de dominio (`returns.*`) por el **outbox** para que otros contextos reaccionen sin
  acople directo. En particular, cuando la disposición es `Restock`, `returns.return_dispositioned` lleva en
  el payload todo lo que `inventory` necesita para `ReceiveStock` (warehouse_id, sku, quantity,
  fulfillment_mode, owner_party_id) — el orquestador cablea ese enganche por el outbox, igual que
  `inbound.goods_received → ReceiveStock`.

## NO es responsabilidad

- No mueve stock por sí mismo: el restock lo hace `inventory` consumiendo el evento (extraíble a microservicio).
- No decide fees ni liquidación: eso es `settlement`.
- No conoce `sellers` ni `network` por composer: el dueño y el almacén viajan como id (UUID) en texto.

## Arquitectura

DDD + CQRS + Clean Architecture (ADR 008): `Presentation → Application → Domain ← Infrastructure`.
Agregado raíz `ReturnOrder`, ids UUID v7 tipados, eventos de dominio al outbox en la transacción del cambio.

## Estado

Fase 4 (devoluciones). La liquidación de sellers (`settlement`) es el otro frente de esta fase.
