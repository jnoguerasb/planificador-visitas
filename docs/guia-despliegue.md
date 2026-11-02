# Guía de despliegue (Fase 9): Neon + GitHub + Render

Esta guía es para la persona que despliega. Claude Code prepara los archivos (`Dockerfile`, `render.yaml`…), pero **nunca ve ni maneja las contraseñas reales**: se crean y se guardan solo en los paneles de Neon y Render y en tu gestor de contraseñas. No las pegues en el chat.

| Parte | Cuándo | Tiempo aproximado |
| --- | --- | --- |
| A. Base de datos en Neon | Ya | 20–30 min |
| B. Repositorio privado en GitHub | Ya | 5–10 min |
| C. Servicio web en Render | Ya: `Dockerfile` y `render.yaml` están en el repositorio (haz `git push` antes) | 15–20 min (+ 5–10 min del primer build) |
| D. Comprobaciones finales | Después de C | 10–15 min |

Resultado final:

```
Navegador ──HTTPS──> Render (contenedor php:8.3-apache) ──TLS──> Neon (PostgreSQL 16)
                     sirve solo public/                          base "planificador"
```

## Antes de empezar: las contraseñas

Vas a necesitar tres. Guárdalas en un gestor de contraseñas desde el momento en que las crees:

| Contraseña | Para qué | Quién la crea |
| --- | --- | --- |
| `planificador_admin` | Propietario de la base: migración y datos de ejemplo. Solo la usas tú desde tu equipo | Neon (la muestra una vez) |
| `app_planificador` | La que usa la aplicación en Render (`DATABASE_URL`). Solo `SELECT`, `INSERT`, `UPDATE` | Tú, en el paso A6 (oculta, con `Read-Host`) |
| `DEMO_PASSWORD` de producción | Contraseña de los usuarios demo publicados | Tú, en el paso C3 |

Recomendación: para `app_planificador`, solo letras y números, al menos 24 caracteres. Así no hay que codificar nada en la URL. Usa una `DEMO_PASSWORD` **distinta** de la de tu `.env` local.

---

## Parte A. Base de datos en Neon (ya)

**A1. Crear la cuenta.** Entra en <https://neon.tech> y regístrate (con GitHub o con email). El plan gratuito es suficiente.

**A2. Crear el proyecto.**
- Nombre: `planificador-visitas`.
- Versión de PostgreSQL: **16**.
- Región: **AWS Europe Central 1 (Frankfurt)**, la misma que usaremos en Render.

Neon crea automáticamente una base `neondb` y un rol `neondb_owner`. No los usaremos para la aplicación.

**A3. Crear el rol propietario.** En el panel del proyecto: *Roles* → *New role* (o *Branches → main → Roles*).
- Nombre: `planificador_admin`.
- Neon muestra la contraseña **una sola vez**: guárdala en el gestor.

**A4. Crear la base.** *Databases* → *New database*.
- Nombre: `planificador`.
- Propietario (*owner*): `planificador_admin`.

**A5. Anotar el host.** Pulsa *Connect*, elige base `planificador` y rol `planificador_admin`.
- **Desactiva "Connection pooling"**. La aplicación fija la zona horaria por conexión, y el *pooler* de Neon (PgBouncer en modo transacción) no conserva ese ajuste entre peticiones.
- Copia solo el **host**. Tiene esta forma: `ep-algo-123456.eu-central-1.aws.neon.tech`. Si contiene `-pooler`, el pooling sigue activado.

**A6. Crear el usuario de la aplicación y cargar el esquema.** En PowerShell, desde la carpeta del proyecto. Primero, la contraseña de `app_planificador`: se escribe oculta y queda solo en una variable de esta ventana.

> Neon no admite `\password` de psql: rechaza las contraseñas ya cifradas ("Neon only supports being given plaintext passwords"). Por eso se pasa así, sin que se vea en pantalla ni quede escrita; viaja cifrada por TLS.

```powershell
. .\scripts\entorno.ps1                                  # pone psql en el PATH
$neon = 'ep-algo-123456.eu-central-1.aws.neon.tech'       # <-- tu host del paso A5
$env:PGCLIENTENCODING = 'UTF8'                            # que los acentos lleguen bien
$s = Read-Host 'Contraseña para app_planificador' -AsSecureString
$env:CLAVE_APP = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))
psql "host=$neon dbname=planificador user=planificador_admin sslmode=require"
```

`psql` pedirá la contraseña de `planificador_admin` (no se ve al escribir). Dentro de `psql` (indicador `planificador=>`), línea a línea:

```sql
CREATE ROLE app_planificador LOGIN;
\getenv clave CLAVE_APP
SELECT format('ALTER ROLE app_planificador WITH PASSWORD %L', :'clave') \gexec
\i db/migrations/001_esquema.sql
\i db/migrations/002_desplazamiento_minimo.sql
\i db/seed.sql
SELECT count(*) AS visitas FROM visitas;
\q
```

Respuestas esperadas:
- `CREATE ROLE` y `ALTER ROLE`; la contraseña no aparece en ninguna.
- `CREATE TABLE` cinco veces, `CREATE INDEX` dos y `GRANT` tres; luego `ALTER TABLE` dos veces (migración 002).
- Los `INSERT` y `visitas = 12`.

La migración ya da a `app_planificador` solo `SELECT`, `INSERT` y `UPDATE`. Al salir, borra la variable temporal: `Remove-Item Env:CLAVE_APP; Remove-Variable s`.

> - Si `CREATE ROLE` da "permiso denegado": sal con `\q` y vuelve a entrar con `user=neondb_owner` (su contraseña está en *Connect*). Haz allí `CREATE ROLE`, `\getenv` y el `ALTER ROLE`, y vuelve a entrar como `planificador_admin` para los `\i`.
> - Si Neon rechaza la contraseña por débil (*entropy*), elige una más larga.
> - Si un `\i` falla a mitad y hay que repetirlo, empieza de cero con `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` (como `planificador_admin`).
**A6 bis. Si ya cargaste la base antes del 02/11/2026** (sin la migración 002), aplícala sola. No borra datos:

```powershell
. .\scripts\entorno.ps1
$neon = 'ep-algo-123456.eu-central-1.aws.neon.tech'       # <-- tu host
$env:PGCLIENTENCODING = 'UTF8'
psql "host=$neon dbname=planificador user=planificador_admin sslmode=require" -f db/migrations/002_desplazamiento_minimo.sql
```

Debe responder `ALTER TABLE` dos veces.

**A7. Comprobar que la aplicación no puede borrar.**

```powershell
psql "host=$neon dbname=planificador user=app_planificador sslmode=require" -c "DELETE FROM visitas WHERE id = 0"
```

Con la contraseña de `app_planificador`, debe responder **permiso denegado**. Si lo hace, la parte A está bien.

**A8. Preparar la `DATABASE_URL`** (no la guardes en ningún archivo del proyecto; solo irá al panel de Render):

```
postgres://app_planificador:CONTRASEÑA_APP@ep-algo-123456.eu-central-1.aws.neon.tech:5432/planificador?sslmode=require
```

Si la contraseña tuviera algún carácter especial (`@ : / # ? %`), habría que codificarlo (`@` → `%40`…). Por eso recomendamos solo letras y números.

---

## Parte B. Repositorio privado en GitHub (ya)

**B1.** En <https://github.com/new> crea un repositorio:
- Nombre: `planificador-visitas`.
- Visibilidad: **Private**.
- **Sin** README, sin `.gitignore` y sin licencia: debe quedar vacío.

**B2.** Comprueba que `.env` no está en git. El comando no debe mostrar nada:

```powershell
git ls-files .env
```

**B3.** Súbelo:

```powershell
git remote add origin https://github.com/TU_USUARIO/planificador-visitas.git
git push -u origin main --tags
```

Más adelante, cuando Claude Code termine las fases que quedan, bastará con `git push` (y `git push --tags` cuando se pongan las etiquetas). Render volverá a desplegar en cada `push` a `main`.

---

## Parte C. Servicio web en Render

`Dockerfile` y `render.yaml` ya están en el repositorio. Antes de empezar, haz `git push` para que GitHub tenga la última versión.

**C1.** Entra en <https://render.com> y regístrate **con GitHub**.

**C2.** *New* → **Blueprint** → elige el repositorio `planificador-visitas`. Si pide permisos, dale acceso **solo a ese repositorio**. Render lee `render.yaml` y propone un servicio web `planificador-visitas`: Docker, región Frankfurt, plan gratuito.

**C3.** Render pedirá los valores marcados como secretos:
- `DATABASE_URL` → la del paso A8.
- `DEMO_PASSWORD` → la contraseña demo de producción.

`APP_ENV=production` ya viene en `render.yaml`.

**C4.** Pulsa *Apply* y abre los *Logs* del servicio. Debes ver, por este orden:
1. El build: instalación de `pdo_pgsql` y `composer install` sin errores.
2. Al arrancar: `Usuarios demo: 5 creados…`. El contenedor crea o actualiza los usuarios demo con `DEMO_PASSWORD` cada vez que arranca.
3. Apache en marcha.

**C5.** La URL pública aparece arriba, con la forma `https://planificador-visitas.onrender.com` (puede llevar un sufijo).

---

## Parte D. Comprobaciones finales (criterios de la Fase 9)

**D1. Build.** El despliegue aparece como *Live* y en los logs del build no hay errores.

**D2. Login con los dos perfiles.** Abre la URL. Entra como `planificador@demo.local` con la `DEMO_PASSWORD` de producción, sal, y entra como `ana@demo.local`.
- La primera petición puede tardar 30–60 s: Render suspende el plan gratuito tras 15 min sin uso y Neon también duerme la base.

**D3. Cabeceras y cookie.** En el navegador, pulsa F12:
- *Red* → recarga → pulsa la petición a `/` → *Encabezados de respuesta*. Deben aparecer `Content-Security-Policy: default-src 'self'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` y `X-Frame-Options: DENY`.
- *Aplicación* (*Almacenamiento* en Firefox) → *Cookies* → `planificador_sesion`: con **Secure**, **HttpOnly** y **SameSite = Strict**.

**D4. Visita inválida → 422 en producción.** Ejecuta el bloque "Probar la API con curl" del README, con dos cambios:
1. `$api = 'https://TU-URL.onrender.com/api'`.
2. Antes del bloque, pon en la sesión la contraseña demo de producción **sin escribirla en el comando**:

```powershell
$env:DEMO_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR((Read-Host 'DEMO_PASSWORD de producción' -AsSecureString)))
```

El paso 2 del bloque debe dar primero `HTTP 201` (o `422` con `SOLAPE` si ya lo habías ejecutado) y después `HTTP 422` con "El desplazamiento mínimo es 30 min"; el paso 3, `HTTP 403`. El paso 2 deja guardada una visita de David dentro de 30 días: es una visita de demostración.

**D5.** Cuéntale a Claude Code el resultado de D1–D4. **Sin contraseñas.**

---

## Problemas frecuentes

| Síntoma | Causa probable | Qué hacer |
| --- | --- | --- |
| La página da 500 o "Ha ocurrido un error" al entrar | `DATABASE_URL` mal escrita, o con el host `-pooler` | *Logs* de Render: el detalle del error está ahí (nunca en la página). Corrige la variable en *Environment* y vuelve a desplegar |
| `password authentication failed` en los logs | Contraseña de `app_planificador` distinta en Neon y en la URL, o con caracteres sin codificar | Cámbiala en Neon con `\password app_planificador` y actualiza `DATABASE_URL` |
| "Usuario o contraseña incorrectos" con la `DEMO_PASSWORD` correcta | Los usuarios demo no se crearon al arrancar | Busca en los logs `Usuarios demo:` o el aviso de error; reinicia el servicio (*Manual Deploy → Restart*) |
| Tarda medio minuto la primera vez | Arranque en frío de Render y Neon (plan gratuito) | Normal; las siguientes peticiones son rápidas |
| Las visitas de ejemplo quedan antiguas | `seed.sql` usa fechas relativas al día en que se cargó | Es esperado en una demo; se puede programar visitas nuevas desde la aplicación |

## Diferencias entre desarrollo y producción

En desarrollo se usa Windows con `php -S`; en producción, Linux con Apache. Esta tabla repasa cada diferencia y cómo está cubierta:

| Aspecto | Desarrollo (Windows) | Producción (Render, Linux) | Cómo está cubierto |
| --- | --- | --- | --- |
| Mayúsculas en rutas y archivos | Windows no las distingue | Linux sí | Todas las rutas de la API van en minúsculas. `InterfazTest` comprueba que cada archivo del HTML y cada `import` de JS existe con las mayúsculas exactas. Las clases PHP se cargan con un mapa generado en el build (`--classmap-authoritative`) a partir de los archivos reales |
| Finales de línea | Git fija LF en todo salvo los `.ps1` | Un script con CRLF no arranca | `.gitattributes`; `DespliegueTest` comprueba que no hay CR en `Dockerfile` ni en `docker/` |
| Servidor web | `php -S`: sirve los archivos de `public/` y manda el resto a `index.php` | Apache + `mod_rewrite`: lo mismo con `RewriteCond !-f` | El router es el mismo `public/index.php`. Solo `public/` es accesible en los dos |
| Cabeceras de seguridad | Las pone PHP; los archivos estáticos (JS, CSS) salen sin ellas | Apache las pone en todo (`Header always set`) y quita la copia de PHP para que no se dupliquen | Comprobación D3 de esta guía |
| Rutas que acaban en `.php` (`/src/Auth.php`) | `php -S` responde su propio 404 | Pasan al router, que da 404 JSON con cabeceras | En ninguno de los dos se sirve código |
| Cookie `Secure` | Los navegadores la aceptan en `http://localhost` | Render termina el HTTPS y reenvía en HTTP al contenedor | La cookie se marca `Secure` siempre, sin intentar detectar HTTPS, así que el proxy no la afecta |
| Control de `Origin` en los POST | Compara con el `Host` de `localhost:8081` | El proxy de Render conserva el `Host` público | Igual en los dos. Si el login diera 403 en producción, este sería el primer sitio a revisar |
| Variables de entorno | `.env` cargado por `entorno.ps1` | Panel de Render; Apache las hereda del contenedor y PHP las lee con `getenv` | `.env` no entra en la imagen (`.dockerignore`) ni en git |
| Errores | `display_errors=0`; el detalle va a la consola de `php -S` | `display_errors=Off`; el detalle va a los *Logs* de Render, sin argumentos de funciones en las trazas | Al cliente solo le llega el mensaje genérico (test de `ManejadorErrores`) |
| Zona horaria | Europe/Madrid (`Reloj`, conexión de PostgreSQL) | Render funciona en UTC | La aplicación fija Europe/Madrid en PHP y en cada conexión, sin depender del servidor |
| Base de datos | PostgreSQL 16 local, sin TLS | Neon (PostgreSQL 16) con `sslmode=require`, host directo | Mismo esquema y mismos dos usuarios. Sin *pooler*: la zona horaria se fija por conexión |
| Usuarios demo | `recrear_bd.ps1` | El contenedor ejecuta `crear_usuarios_demo.php` al arrancar | El script es idempotente; si falla, Apache arranca igualmente y se registra un aviso |
| Sesiones | Archivos en la carpeta temporal | Archivos en `/tmp` del contenedor | Se pierden al redesplegar o al reiniciar el contenedor: hay que volver a entrar. Aceptable para la demo |
| Dependencias | PHPUnit y pcov | Solo el autoload (`--no-dev`), sin pcov ni Xdebug | `DespliegueTest` |
| Arranque en frío | Inmediato | 30–60 s tras 15 min sin uso (plan gratuito de Render y Neon) | Avisado en el README y en esta guía |