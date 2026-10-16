# logistics/sellers

Contexto de **sellers / proveedores de marketplace** (ADR 017, Fase 1 del roadmap
`docs/marketplace-dropship-roadmap.md`).

## Responsabilidad

El seller como **party de primera clase**: alta y ciclo de vida (onboarding → activo → suspendido),
**términos de liquidación** (comisión, fees, ciclo de pago), **catálogo de SKUs del seller** (y su
equivalencia opcional con material SAP), y datos de contacto. Es el "dueño" que la dimensión de propiedad
(`Logistics\Shared\Domain\ValueObject\Ownership`) referencia por id.

## No-responsabilidad

- NO recibe mercadería (eso es `inbound`, Fase 2).
- NO lleva stock (eso es `inventory`, Fase 3).
- NO liquida pagos (eso es `settlement`, Fase 4).
- NO toca SAP (eso es `sap-connector`).

## Estado

Fase 1 — en construcción. DDD + CQRS + Clean Architecture, UUID v7, outbox (ADR 008).
