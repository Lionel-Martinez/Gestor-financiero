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

## Decisiones aprobadas (2026-11-22)

- **S-1 a S-7 aprobados** por Lionel ("si lo apruebo"). S-4 sigue sujeta a la revisión de privacidad y S-3 se retoma al implementar [[05-Roadmap]] R-1.
- **D-1 Diseño visual: propuesta B (oscura)** elegida entre A (clara) y B. Fondo casi negro `#0E1311`, verde lima `#B8F35B` como color principal, ingresos azul `#6FB1FF`, gastos naranja `#FFA05C`, tipografía DM Sans, formas con poco redondeo. Maquetas en el lienzo "Gestor Financiero — Maquetas v1".
- **D-2 Resumen anual**: tabla año por año (ingresos, gastos, resultado) por fecha contable, **por moneda**. La conversión "Todo en ARS" queda pendiente hasta que la app reciba cotizaciones ([[05-Roadmap]] R-6).
- **D-3 Navegación**: barra inferior Inicio · Movimientos · Nuevo · Anual; Cuentas sigue dentro de Inicio (pantalla de Cuentas sin diseñar).
- **D-4 Rediseño visual: propuesta C** (clara, sobria, verde `#1E7B4F`, listas densas tipo extracto, barra superior verde con pestañas subrayadas, sistema tipográfico del dispositivo). Reemplaza a D-1 (B oscura) porque la B resultó "demasiado IA". El modo oscuro sigue disponible en Ajustes con la misma paleta verde. Pendiente de que Lionel la vea en la app real.

