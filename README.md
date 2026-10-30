# Planificador de visitas a obras

Aplicación web para planificar las visitas de los técnicos a las obras. PHP 8.3 sin framework, PostgreSQL 16 y JavaScript vanilla.

> Estado: **Fase 6** (interfaz del planificador: login, nueva visita con previsualización, agenda, listado y cancelación). La guía de trabajo está en `CLAUDE.md` y el análisis funcional en `docs/analisis.md`.

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
| `.\scripts\probar_interfaz.ps1` | Abre la página en Edge sin ventana y comprueba que el JavaScript carga, se ve el login y la consola no tiene errores (no forma parte de `composer verify`: necesita Edge) |

Cobertura de la suite `unit` (requiere `pcov`): `php -d pcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text`. Lista legible de los tests: `php vendor/bin/phpunit --testsuite unit --testdox`.

## API

Todas las respuestas son JSON. Los errores tienen siempre la forma `{"errores": [{"regla", "mensaje", "campo"?}]}`.

| Método | Ruta | Quién | Respuestas |
| --- | --- | --- | --- |
| POST | `/api/login` | Público (sin token CSRF) | 200 con `usuario` y `csrf`; 401 |
| POST | `/api/logout` | Ambos | 204 |
| GET | `/api/yo` | Ambos | 200 con `usuario` y `csrf`; 401 |
| GET | `/api/tecnicos`, `/api/obras` | Planificador | 200; 403 |
| GET | `/api/visitas?tecnico_id=&fecha=&estado=` | Ambos (al técnico se le fuerzan las suyas) | 200; 422 |
| GET | `/api/agenda?tecnico_id=&fecha=` | Ambos (el técnico, solo la suya) | 200; 403; 422 |
| POST | `/api/visitas/validar` | Planificador | 200 con `valida`, `origen`, `siguiente` y `errores` (no guarda nada) |
| POST | `/api/visitas` | Planificador | 201 con `id`; 403; 422 |
| POST | `/api/visitas/{id}/completar` con `comentario` | Técnico (las suyas) | 200; 403; 404; 409; 422 |
| POST | `/api/visitas/{id}/cancelar` con `motivo` | Ambos (el técnico, las suyas) | 200; 403; 404; 409; 422 |

Además: sin sesión, 401; JSON mal formado o sin `Content-Type: application/json`, 400; campos no esperados, 422; POST sin la cabecera `X-CSRF-Token` (salvo el login) o desde otro sitio (`Origin`), 403.

### Probar la API con curl

Así se comprueba que las reglas y los permisos no dependen del navegador. Con la base recién cargada (`.\scripts\recrear_bd.ps1`) y el servidor en marcha en otra terminal (`.\scripts\servidor.ps1`), en PowerShell:

```powershell
. .\scripts\entorno.ps1            # carga DEMO_PASSWORD de .env
$api = 'http://localhost:8081/api'
$cookies = Join-Path $env:TEMP 'planificador_cookies.txt'
$peticion = Join-Path $env:TEMP 'planificador_peticion.json'
function Enviar([string]$ruta, $datos, [string]$token = '') {
    # El JSON va en un archivo temporal (UTF-8 sin BOM) que se borra justo después.
    [IO.File]::WriteAllText($peticion, ($datos | ConvertTo-Json -Compress))
    curl.exe -s -w "`nHTTP %{http_code}`n" -b $cookies -c $cookies -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" --data-binary "@$peticion" "$api$ruta"
    Remove-Item $peticion
}

# 1. Entrar como planificador y quedarse con el token CSRF
$token = ((Enviar '/login' @{ email = 'planificador@demo.local'; password = $env:DEMO_PASSWORD }) | Select-Object -First 1 | ConvertFrom-Json).csrf

# 2. Visita inválida (desplazamiento 20): HTTP 422 y "El desplazamiento mínimo es 30 min"; no se guarda nada
Enviar '/visitas' @{ tecnico_id = 1; obra_id = 2; fecha = (Get-Date).AddDays(7).ToString('yyyy-MM-dd'); hora_inicio = '12:00'; duracion_min = 60; desplazamiento_min = 20 } $token

# 3. Ana intenta cancelar la visita 6, que es de Bernat: HTTP 403 "No tienes permiso para modificar esta visita"
Remove-Item $cookies
$token = ((Enviar '/login' @{ email = 'ana@demo.local'; password = $env:DEMO_PASSWORD }) | Select-Object -First 1 | ConvertFrom-Json).csrf
Enviar '/visitas/6/cancelar' @{ motivo = 'Prueba de permisos' } $token

Remove-Item $cookies
```
