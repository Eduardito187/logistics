# Visión — Plataforma logística Dismac

Documento completo (vivo, con diagramas):
https://claude.ai/code/artifact/3e9f11c9-3990-4403-9f1f-6485b14ba859

Este archivo resume lo esencial, para tenerlo junto al código.

## Problema

- El sistema de despacho actual es rígido, con campos restringidos. Solo acepta productos SAP con
  todas sus validaciones.
- El cliente no ve el estado de su pedido.
- El sistema da coordenadas, pero los choferes le piden la ubicación al cliente.
- No hay trazabilidad de qué se cargó y qué se descargó.
- Cupos, fechas, horarios, asignación chofer-camión, qué camiones salen y coberturas se deciden sin sistema.

## Qué es

Una plataforma propia, fuera de SAP, que planifica, ejecuta y hace visible cada entrega. Cubre la red
completa: CD→cliente, CD→CD, CD→tienda, tienda→tienda, tienda→CD y recojo en tienda.

Escala objetivo: ~100 camiones a nivel nacional, ~300 entregas diarias.

## Decisiones tomadas

| Tema | Decisión |
| --- | --- |
| SAP | Fuera del dominio SAP; integración solo vía conector con outbox |
| Stock | No se valida stock: solo se reciben pedidos para entregar |
| dis-commerce | Fuera del MVP (arranca en ~6 meses); luego se conecta como un adaptador más |
| Fuentes de pedidos | SAP, Magento actual, tiendas, carga manual o Excel |
| Repos | `logistics` (backend + admin) y `logistics-app` (app interna Flutter) |
| App | Una sola app interna con vista por rol: chofer, preparador, tienda, transportista, supervisor |
| Etiquetas | Una por unidad de producto del pedido; QR con JSON compacto firmado Ed25519 |
| Impresión | Desde la app a impresora portátil Zebra (ZQ630 Plus) por Bluetooth o WiFi, en ZPL |
| Entre departamentos | Por volumen del corredor: tráiler, camión o transportista tercero |
| Notificaciones | Sin WhatsApp (costo). Push de la app de clientes + web push + email; SMS solo respaldo crítico |
| Fuera de alcance | Instalación y armado (otros flujos y sistemas) |

## Fases

| Fase | Qué demuestra |
| --- | --- |
| 0. Discovery | Línea base de KPIs, calidad de coordenadas, flota real |
| 1. Operar (MVP) | Piloto en una ciudad con 10–15 camiones, CD→cliente. "Sabemos qué sale, en qué camión, con quién, y el cliente lo ve" |
| 2. Prometer | Cupos, promesa, conector SAP, recepción en nodos, recojo en tienda, consolidación entre departamentos |
| 3. Optimizar | Flota de menor costo, pronóstico, simulador, rentabilidad por entrega |

## KPIs del piloto

1. Llamadas del chofer al cliente por ubicación.
2. Consultas "¿dónde está mi pedido?" al call center.
3. % de entregas a tiempo y fallidas, con motivo.
4. Bultos descargados por error o faltantes.
5. Satisfacción post-entrega.
