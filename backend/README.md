# Backend — Gestor Financiero (Laravel API)

API pura para la app móvil. **No hay frontend web.**

## Instalación

```bash
./install.sh                 # crea el skeleton de Laravel + Sanctum y superpone este código
docker compose up -d mysql   # MySQL 8.4 (o usa el tuyo)
php artisan migrate
php artisan gf:create-user tu@correo.com "Tu Nombre"
php artisan test
```

> Estado de verificación: el dominio puro (`app/Domain`) tiene tests unitarios ejecutados. Migraciones, triggers MySQL,
> servicios y tests de integración (`tests/Feature`) **no se ejecutaron** todavía contra Laravel/MySQL: córrelos antes de confiar en ellos.

## Estructura

- `app/Domain`: reglas puras sin base de datos (`Decimal`, `EntryValidator`, `Posting`, `Totp`).
- `app/Services`: `LedgerService` (único escritor del ledger), `OperationService`, `SyncService`, `DashboardService`.
- `database/migrations`: esquema, vistas y protecciones (CHECK + triggers solo en MySQL/MariaDB).
- `routes/api.php`: `/api/v1/*`.

## Usuarios de base de datos (mínimo privilegio)

- `gf_migrator`: migraciones (CREATE/ALTER/DROP/TRIGGER).
- `gf_app`: la aplicación en ejecución. `SELECT, INSERT, UPDATE` sobre las tablas; **sin DELETE** sobre `journal_*`, `audit_log`,
  `reversal_allocations`, `exchange_rates`; sin DDL ni TRIGGER.

Así los triggers protegen contra errores de la aplicación y los permisos contra una cuenta comprometida, sin impedir migraciones legítimas.
