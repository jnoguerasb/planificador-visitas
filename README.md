# Planificador de visitas a obras

Aplicación web para planificar las visitas de los técnicos a las obras. PHP 8.3 sin framework, PostgreSQL 16 y JavaScript vanilla.

> Estado: **Fase 0** (entorno base). La guía de trabajo está en `CLAUDE.md` y el análisis funcional en `docs/analisis.md`.

## Requisitos (Windows)

| Programa | Versión | Dónde |
| --- | --- | --- |
| PHP | 8.3, Non Thread Safe x64, en `C:\php83` | https://windows.php.net/download |
| PostgreSQL | 16, instalado como servicio en el puerto 5432 | https://www.enterprisedb.com/downloads/postgres-postgresql-downloads |
| Composer | 2.x | https://getcomposer.org |
| Git for Windows | cualquiera reciente (incluye `bash`) | https://git-scm.com |

En `C:\php83\php.ini` deben estar activadas las extensiones `curl`, `mbstring`, `openssl`, `pdo_pgsql` y `zip`, y `display_errors = Off`.

Si PowerShell no deja ejecutar scripts, ejecuta una vez: `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`.

## Arranque

En PowerShell, desde la carpeta del proyecto:

```powershell
Copy-Item .env.example .env        # y edita .env con tus valores
. .\scripts\entorno.ps1            # con el punto delante: carga PHP 8.3 y .env en esta sesión
composer install
.\scripts\crear_bases.ps1          # pide la contraseña del superusuario postgres
composer verify                    # lint + tests + comprobaciones de seguridad
.\scripts\servidor.ps1             # http://localhost:8080 (Ctrl+C para parar)
```

Si el puerto 8080 está ocupado por otro programa, usa otro: `.\scripts\servidor.ps1 -Puerto 8081`.

`entorno.ps1` solo cambia el `PATH` de la sesión actual. No toca el PHP de XAMPP ni el `PATH` del sistema. Hay que cargarlo en cada terminal nueva.

## Comandos

| Comando | Qué hace |
| --- | --- |
| `composer lint` | `php -l` sobre `src/`, `public/`, `bin/` y `tests/` |
| `composer test:unit` | Tests sin base de datos |
| `composer test:integration` | Tests contra `planificador_test` |
| `composer test:api` | Tests HTTP contra un servidor PHP de pruebas |
| `composer test` | Las tres suites |
| `composer check:security` | `scripts/check-security.sh` |
| `composer verify` | Todo lo anterior. Es la puerta mínima de cada fase |
