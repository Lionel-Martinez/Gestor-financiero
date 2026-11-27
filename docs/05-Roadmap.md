---
tipo: roadmap
---
# Roadmap

## Hecho en esta entrega (núcleo V1)
- Espacios, plan de cuentas de sistema, cuentas financieras multimoneda, categorías (2 niveles).
- Asientos de doble partida: validar, confirmar, numerar, revertir (total/parcial), períodos con desplazamiento.
- Operaciones: ingreso, gasto, transferencia propia, transferencia entre espacios, conversión con cuentas puente, saldo inicial.
- Sincronización offline: cola, idempotencia, conflictos, pull incremental.
- Dashboard móvil: contexto General/Personal/LM Studio, períodos, 4 indicadores, ingresos vs. gastos, gastos por categoría, actividad reciente, cuentas agrupadas, modo claro/oscuro.
- Auth con TOTP y cierre remoto de sesiones.

## Falta para completar la V1 del documento de diseño
| Id | Módulo | Nota |
|---|---|---|
| R-1 | Tarjetas, compras en cuotas, resúmenes | En curso (D-5): etapas A y B (servidor) hechas; faltan C (sincronización y base local) y D (pantallas). Interés de cuotas (S-3) en segunda etapa |
| R-2 | Recurrencias, obligaciones, comprobantes, cobros previstos | Alimenta los paneles "próximos" del dashboard |
| R-3 | Préstamos y deudas (cronogramas) | |
| R-4 | Presupuestos, metas y dinero reservado | |
| R-5 | Impuestos y perfiles fiscales | Registro manual |
| R-6 | Cotizaciones (carga y selección) y proyecciones | Hoy solo se leen de `exchange_rates` |
| R-7 | Conciliación e importación CSV/Excel | |
| R-8 | Reportes y exportación PDF/XLSX/CSV | |
| R-9 | Adjuntos con SHA-256 | |
| R-10 | Backups y restauración verificada | |
| R-11 | Notificaciones push/email | |
| R-12 | Cierre/reapertura de períodos desde la app | La lógica existe en servidor |
| R-13 | UI de reversión y de resolución de conflictos | El backend ya lo soporta |
| R-14 | Gráficos de evolución de saldo y por medio de pago | |

## Verificación pendiente (importante)
Ejecutar en una máquina con acceso a Packagist: `backend/install.sh`, `php artisan migrate` sobre **MySQL 8.4** y `php artisan test`. Hasta entonces las migraciones, los triggers y los servicios con base de datos son código sin probar.
| R-15 | Resumen anual convertido a ARS y pantalla propia de Cuentas | Depende de R-6 (cotizaciones en la app) y de diseño |
