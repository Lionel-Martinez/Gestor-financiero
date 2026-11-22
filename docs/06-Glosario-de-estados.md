---
tipo: glosario
---
# Glosario de estados

La palabra "pendiente" aparece con significados distintos; esta tabla los separa.

| Concepto | Estados | Dónde vive |
|---|---|---|
| Asiento (servidor) | `draft`, `pending_review`, `confirmed` + **revertido derivado** (none/partial/full) | `journal_entries`, `v_entry_reversal` |
| Operación en el dispositivo | `pending` (sin sincronizar), `rejected`, `confirmed` | SQLite local |
| Cola de sincronización | `pending`, `applied`, `rejected`, `conflict` | `outbox` / `sync_operations` |
| Movimiento en el dashboard | Confirmado · Pendiente de sincronizar (· Previsto: llega con recurrencias) | UI |
| Obligación / comprobante | previsto, pendiente de factura, pendiente de pago, pago parcial, pagado, vencido, cancelado | roadmap R-2 |
| Período contable | `open`, `closed` | `accounting_periods` |
