---
tipo: arquitectura
---
# Arquitectura

```
App móvil (Ionic Vue + Capacitor)            Servidor
┌───────────────────────────────┐            ┌──────────────────────────────┐
│ Vistas (Dashboard, Ajustes)   │            │ Laravel API  /api/v1         │
│ Pinia store                   │            │  Auth + Sanctum + TOTP       │
│ Dominio TS (money/validator/  │  push/pull │  SyncService (idempotente)   │
│   posting) = port del PHP     │◄──────────►│  OperationService            │
│ SQLite local (outbox, lines…) │   HTTPS    │  LedgerService (único writer)│
└───────────────────────────────┘            │ MySQL 8.4 (+triggers, vistas)│
                                             └──────────────────────────────┘
```

## Flujo offline → servidor
1. El usuario crea una operación (gasto, ingreso, transferencia, cuenta…). La app la **valida con las mismas reglas** que el servidor.
2. Se guardan, en una sola transacción SQLite, los asientos provisionales y la operación en la cola `outbox` (UUID propio).
3. Con conexión, `sync/push` envía la cola en orden. El servidor aplica cada operación **de forma atómica** e **idempotente** (el id de operación es clave primaria).
4. `sync/pull` trae lo confirmado (con número contable); el asiento confirmado reemplaza al provisional por UUID.
5. Rechazo (reglas contables) o conflicto (versión base distinta) → se informa; se conservan ambas versiones y se resuelve de forma explícita.

## Capas de protección del ledger
1. Reglas puras de dominio (`EntryValidator`), repetidas en TypeScript.
2. `LedgerService` como único punto de escritura, con transacciones y bloqueo de secuencia.
3. Restricciones MySQL: CHECK de apuntes, claves foráneas compuestas por espacio.
4. Triggers: un asiento confirmado y sus apuntes no se modifican ni eliminan; `audit_log`, `reversal_allocations` y `exchange_rates` son inalterables.
5. Usuarios de base de datos de mínimo privilegio (ver [[04-Seguridad-y-despliegue]]).
