---
tipo: decisiones
---
# Decisiones

## Aprobadas (del documento de diseño)
- App móvil Android/iOS; backend Laravel solo API; sin frontend web.
- Stack: Ionic Vue + TypeScript + Capacitor · Laravel · MySQL 8.4 LTS (InnoDB, utf8mb4) · SQLite local.
- UUID v4 CHAR(36) generados en el dispositivo; importes DECIMAL(19,4); cotizaciones DECIMAL(20,8); fechas UTC.
- Espacios Personal y LM Studio contables; General solo consolida. Transferencias entre espacios vinculadas con un `operation_id`.
- Doble partida obligatoria, ledger append-only, una moneda por asiento, numeración PER-/LMS-AAAA-NNNNNN al confirmar.
- Offline completo, sincronización idempotente, conflictos financieros sin last-write-wins.
- 2FA TOTP opcional (sin SMS) con códigos de recuperación de un solo uso.

## Supuestos tomados para poder implementar V1 — PENDIENTES DE TU APROBACIÓN

Pediste avanzar directo a la V1 sin cerrar las revisiones pendientes. Tomé la opción recomendada en cada punto y la dejé aislada para que sea fácil cambiarla.

| Id | Tensión detectada | Supuesto implementado | Dónde |
|---|---|---|---|
| S-1 | "Revertido" como estado vs. ledger inalterable | El estado almacenado es `draft/pending_review/confirmed`. "Revertido" se **deriva** de las reversiones (`v_entry_reversal`); el asiento original nunca se modifica. | migración ledger, `LedgerService::reverse` |
| S-2 | Offline completo vs. numeración correlativa en servidor | Offline se crean **operaciones** y asientos *provisionales* (sin número, "pendiente de sincronizar"). El servidor valida, numera y confirma. El **saldo real** es solo lo confirmado; el pendiente se muestra aparte. | `mobile/src/data/repo.ts`, `SyncService` |
| S-3 | Cuotas "no generan gasto" vs. intereses como gasto financiero | Gasto por el precio original al comprar; el interés se reconoce como gasto financiero al vencer cada cuota. **No implementado aún** (módulo de tarjetas/cuotas en roadmap). | [[05-Roadmap]] |
| S-4 | Anonimización vs. append-only | Se anonimizan datos personales en `users`/`devices`, nunca en `journal_*` ni `audit_log`; el audit guarda ids, no datos personales. | pendiente de revisión de privacidad |
| S-5 | Año de la numeración | Se usa el año de la **fecha contable** efectiva. | `LedgerService::confirm` |
| S-6 | Moneda base del Patrimonio en General | Por defecto ARS (configurable por usuario) con cotización de referencia `oficial`. Si falta una cotización el total convertido es `null`; nunca se inventa. | `DashboardService` |
| S-7 | Acceso | V1 de **un solo usuario** (alta por `php artisan gf:create-user`, sin registro público). El modelo ya separa por `user_id`/espacio para crecer a más roles. | [[04-Seguridad-y-despliegue]] |

Las tensiones y su resolución completa están en [[02-Arquitectura]] y [[03-Modelo-de-datos]].
