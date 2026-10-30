# Planificador de visitas a obras

Aplicación web para planificar las visitas de los técnicos a las obras. PHP 8.3 sin framework, PostgreSQL 16 y JavaScript vanilla.

> Estado: **Fase 4** (seguridad transversal: login, sesión, CSRF, cabeceras y router con `POST /api/login`, `POST /api/logout` y `GET /api/yo`). La guía de trabajo está en `CLAUDE.md` y el análisis funcional en `docs/analisis.md`.

## Requisitos (Windows)

| Programa | Versión | Dónde |
| --- | --- | --- |
| PHP | 8.3, Non Thread Safe x64, en `C:\php83` | https://windows.php.net/download |
| PostgreSQL | 16, instalado como servicio en el puerto 5432 | https://www.enterprisedb.com/downloads/postgres-postgresql-downloads |
| Composer | 2.x | https://getcomposer.org |
| Git for Windows | cualquiera reciente (incluye `bash`) | https://git-scm.com |

En `C:\php83\php.ini` deben estar activadas las extensiones `curl`, `mbstring`, `openssl`, `pdo_pgsql` y `zip`, y `display_errors = Off`.

Para medir la cobertura de los tests (opcional): copia `php_pcov.dll` de [pcov 1.0.12 para PHP 8.3 NTS x64](https://downloads.php.net/~windows/pecl/releases/pcov/1.0.12/) en `C:\php83\ext` y añade al `php.ini` `extension=pcov` y `pcov.enabled=0`.

Si PowerShell no deja ejecutar scripts, ejecuta una vez: `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`.

## Arranque

En PowerShell, desde la carpeta del proyecto:

```powershell
Copy-Item .env.example .env        # y edita .env con tus valores
. .\scripts\entorno.ps1            # con el punto delante: carga PHP 8.3 y .env en esta sesión
composer install
.\scripts\crear_bases.ps1          # roles y bases; pide la contraseña del superusuario postgres
.\scripts\recrear_bd.ps1           # esquema, datos de ejemplo y usuarios demo
composer verify                    # lint + tests + comprobaciones de seguridad
.\scripts\servidor.ps1             # http://localhost:8081 (Ctrl+C para parar)
```

Si el puerto 8081 está ocupado por otro programa, usa otro: `.\scripts\servidor.ps1 -Puerto 8082`.

### Base de datos

- Dos usuarios, ambos sin privilegios de superusuario: `planificador_admin` (`DATABASE_ADMIN_URL`), propietario de las bases, ejecuta migraciones y seed; y `app_planificador` (`DATABASE_URL`), el de la aplicación, que solo puede `SELECT`, `INSERT` y `UPDATE`.
- `crear_bases.ps1` crea los dos usuarios con las contraseñas de `.env` y las bases `planificador` y `planificador_test`. Si cambias una contraseña en `.env`, vuelve a ejecutarlo.
- `recrear_bd.ps1` **borra todos los datos** de la base de desarrollo y la vuelve a cargar con `db/migrations/001_esquema.sql`, `db/seed.sql` y los usuarios demo.
- Los tests de integración recrean `planificador_test` por su cuenta.

### Usuarios demo

Contraseña: la de `DEMO_PASSWORD` en tu `.env`.

| Email | Perfil |
| --- | --- |
| `planificador@demo.local` | Planificador |
| `ana@demo.local`, `bernat@demo.local`, `carla@demo.local`, `david@demo.local` | Técnico |

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

Cobertura de la suite `unit` (requiere `pcov`): `php -d pcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text`. Lista legible de los tests: `php vendor/bin/phpunit --testsuite unit --testdox`.
