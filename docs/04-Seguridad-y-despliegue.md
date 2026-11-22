---
tipo: seguridad
---
# Seguridad y despliegue (borrador con supuestos)

## Implementado
- Contraseña con hash (bcrypt), tokens Sanctum **por dispositivo** con vencimiento (90 días), cierre remoto de sesión.
- 2FA TOTP (RFC 6238, verificado con los vectores oficiales) y códigos de recuperación guardados solo como hash SHA-256, de un solo uso.
- Límite de intentos en login y en sync.
- Auditoría append-only con usuario, dispositivo, motivo, estado anterior y posterior.

## Mínimo privilegio en MySQL
- `gf_migrator`: DDL y triggers (solo para migrar).
- `gf_app`: `SELECT, INSERT, UPDATE` sin `DELETE` sobre `journal_*`, `audit_log`, `reversal_allocations`, `exchange_rates`; sin DDL ni `TRIGGER`.

## Pendiente de decidir (revisiones abiertas)
- **Permisos:** V1 es de un solo usuario (S-7). Falta definir roles (contador, socio) y quién autoriza reaperturas de período.
- **Privacidad y conservación:** plazos de retención, anonimización (S-4), cifrado de la base local (SQLCipher) y de adjuntos.
- **Despliegue y monitoreo:** hosting, HTTPS, backups cifrados con prueba de restauración periódica, alertas de backup fallido, rotación de logs.
- **Almacenamiento del token** en el dispositivo: hoy usa Preferences de Capacitor; se recomienda Keychain/Keystore.
