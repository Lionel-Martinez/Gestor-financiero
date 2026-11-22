---
tipo: tablero
proyecto: gestor-financiero
actualizado: 2026-11-22
---
# Tablero de Control — Gestor Financiero

App móvil Android/iOS (Ionic Vue + Capacitor) con backend Laravel API, MySQL 8.4 y SQLite local. **No hay frontend web.**

## Estado

| Área | Estado | Detalle |
|---|---|---|
| Dominio contable (PHP) | ✅ Verificado | 38 tests ejecutados (decimales, validador, asientos, TOTP) |
| Dominio + datos locales (TS) | ✅ Verificado | 23 tests ejecutados (sql.js), typecheck y build OK |
| Migraciones, triggers MySQL | ⚠️ Sin ejecutar | Escritos; requieren MySQL 8.4 real |
| Servicios Laravel, API, tests de integración | ⚠️ Sin ejecutar | Packagist bloqueado en el entorno de desarrollo; ver [[05-Roadmap]] |
| UI móvil | ⚠️ Compila, sin revisión visual | No se probó en dispositivo ni navegador |
| Revisiones pendientes (permisos, privacidad, despliegue) | 🟡 Con supuestos | Ver [[01-Decisiones]] |

## Documentos
- [[01-Decisiones]] — aprobadas, supuestos pendientes de aprobación y tensiones resueltas
- [[02-Arquitectura]]
- [[03-Modelo-de-datos]]
- [[04-Seguridad-y-despliegue]]
- [[05-Roadmap]] — qué entra en V1 y qué falta
- [[06-Glosario-de-estados]]

## Próximas acciones
1. Ejecutar `backend/install.sh`, migrar en MySQL 8.4 y correr `php artisan test`.
2. Aprobar o corregir los supuestos S-1 a S-7 de [[01-Decisiones]].
3. Probar la app en un emulador (`npm run cap:sync`, `npx cap add android`).
