# Planificador de visitas a obras

Aplicación web para planificar las visitas de los técnicos a las obras. Hecha con PHP 8.3 sin framework, PostgreSQL 16 y JavaScript vanilla sin build.

**Aplicación publicada:** https://planificador-visitas.onrender.com (Render + Neon, plan gratuito: la primera petición tras un rato sin uso puede tardar 30–60 s).

| Usuario | Perfil |
| --- | --- |
| `planificador@demo.local` | Planificador: programa visitas viendo la agenda del técnico, filtra el listado y cancela |
| `ana@demo.local`, `bernat@demo.local`, `carla@demo.local`, `david@demo.local` | Técnico: ve su agenda del día, completa (con comentario) o cancela (con motivo) sus visitas |

La contraseña de la versión publicada se entrega aparte. En local es la que elijas al arrancar.

## Ejecutar en local (menos de 10 minutos)

Requisitos: PHP 8.3 con la extensión `pdo_pgsql` activada, PostgreSQL 16 y Composer. `php`, `psql` y `composer` deben estar en el `PATH`.

Al arrancar se piden tres contraseñas que eliges tú: las de los dos usuarios de base de datos y la de los usuarios demo. Usa solo letras y números, porque van dentro de una URL de conexión. No se guardan en ningún archivo.

**Linux / macOS (bash):**

```bash
git clone https://github.com/jnoguerasb/planificador-visitas.git
cd planificador-visitas
composer install

read -rsp 'Contraseña para planificador_admin: ' CLAVE_ADMIN; echo
read -rsp 'Contraseña para app_planificador: ' CLAVE_APP; echo
read -rsp 'Contraseña de los usuarios demo: ' DEMO_PASSWORD; echo
export DEMO_PASSWORD DATABASE_URL="postgres://app_planificador:${CLAVE_APP}@localhost:5432/planificador"

# Usuarios y bases de datos (pide la contraseña del superusuario postgres)
psql -h localhost -U postgres -v ON_ERROR_STOP=1 -v clave_admin=$CLAVE_ADMIN -v clave_app=$CLAVE_APP -f db/crear_bases.sql
# Esquema y datos de ejemplo
PGPASSWORD=$CLAVE_ADMIN psql -h localhost -U planificador_admin -d planificador -1 -v ON_ERROR_STOP=1 -f db/migrations/001_esquema.sql -f db/seed.sql
# Usuarios demo y servidor
php bin/crear_usuarios_demo.php
php -S localhost:8081 -t public
```

**Windows (PowerShell):**

```powershell
git clone https://github.com/jnoguerasb/planificador-visitas.git
cd planificador-visitas
composer install

$claveAdmin = Read-Host 'Contraseña para planificador_admin'
$claveApp = Read-Host 'Contraseña para app_planificador'
$env:DEMO_PASSWORD = Read-Host 'Contraseña de los usuarios demo'
$env:DATABASE_URL = "postgres://app_planificador:$claveApp@localhost:5432/planificador"

psql -h localhost -U postgres -v ON_ERROR_STOP=1 -v clave_admin=$claveAdmin -v clave_app=$claveApp -f db/crear_bases.sql
$env:PGPASSWORD = $claveAdmin
psql -h localhost -U planificador_admin -d planificador -1 -v ON_ERROR_STOP=1 -f db/migrations/001_esquema.sql -f db/seed.sql
Remove-Item Env:PGPASSWORD
php bin/crear_usuarios_demo.php
php -S localhost:8081 -t public
```

Abre **http://localhost:8081**, con `localhost` y no con una IP: la cookie de sesión es `Secure` y el navegador solo la acepta sin HTTPS en `localhost`.

- **Si el puerto 8081 está ocupado:** usa otro, por ejemplo `-S localhost:8082`.
- **Para volver a arrancar en otra terminal:** basta con definir `DATABASE_URL` y ejecutar `php -S`.

### Tests

Con las variables del paso anterior definidas en la misma terminal:

```bash
export DATABASE_URL_TEST="postgres://app_planificador:${CLAVE_APP}@localhost:5432/planificador_test"
export DATABASE_ADMIN_URL_TEST="postgres://planificador_admin:${CLAVE_ADMIN}@localhost:5432/planificador_test"
composer verify
```

En PowerShell, igual pero con `$env:DATABASE_URL_TEST = "..."`.

`composer verify` hace tres cosas:
- **`composer lint`:** revisa la sintaxis de los archivos PHP.
- **`composer test`:** 411 tests, en tres suites:
  - `unit`: reglas de negocio, sin base de datos;
  - `integration`: contra `planificador_test`, que se recrea sola;
  - `api`: HTTP contra un servidor PHP de pruebas.
- **`composer check:security`:** busca `innerHTML`, SQL concatenado, secretos o `display_errors` activado. Necesita `bash`; en Windows, el de Git for Windows.

Los tests se niegan a usar una base cuyo nombre no acabe en `_test`.

## Qué he hecho y qué queda pendiente

**Hecho:**
- **Lo que pide el enunciado:** programar visitas sin solapes, dentro de la jornada de 08:00 a 17:00 y con al menos 30 min de desplazamiento entre obras diferentes.
- **Ampliación 1, desplazamiento variable:** cada visita guarda su desplazamiento (de 0 a 240 min) y su origen (la sede o la visita anterior). Lo calcula el servidor. Al intercalar o cancelar una visita, se actualiza la siguiente.
- **Ampliación 2, perfiles:** hay planificador y técnico. El técnico completa sus visitas con un comentario y las cancela con un motivo; queda registrado quién y cuándo.
- **Ampliación 3, agenda diaria:** muestra la agenda del técnico con los huecos libres. Antes de guardar, la visita propuesta aparece en verde si cabe o en rojo si choca, con la misma validación que al guardar.
- **Tests y despliegue:** 411 tests automáticos; publicada en Render (Docker) con la base de datos en Neon.

**Pendiente:**
- gestión de técnicos, obras y usuarios desde pantalla;
- editar o reprogramar visitas;
- vuelta a la sede al final del día;
- festivos y jornadas por técnico;
- avisos;
- paginación;
- límite de intentos de login.

## Decisiones principales

- **PHP sin framework, PostgreSQL y JS vanilla.** Quien mantenga el código conoce PHP, JS y SQL; así no tiene que aprender un framework ni mantener un build.
- **Reglas en un solo sitio.** Están en clases sin base de datos (`ValidadorVisita`, `CalculadoraAgenda`, `Permisos`) y las constantes en `Reglas`. La previsualización (`POST /api/visitas/validar`) usa la misma validación que Guardar. El navegador solo muestra lo que dice el servidor.
- **Se rechaza, no se corrige.** Un desplazamiento de 20 min entre obras devuelve 422 con "El desplazamiento mínimo es 30 min". El servidor nunca lo cambia a 30 por su cuenta.
- **Concurrencia.** Cada escritura bloquea al técnico (`SELECT … FOR UPDATE`) antes de validar. Así, dos planificadores no pueden guardar a la vez visitas incompatibles. Un test con dos procesos lo comprueba.
- **Base de datos como segunda barrera.** Los `CHECK` repiten los límites del validador. La aplicación se conecta con un usuario que solo puede `SELECT`, `INSERT` y `UPDATE`: las visitas nunca se borran, solo cambian de estado.
- **Seguridad.**
  - Solo sentencias preparadas.
  - Cookie de sesión `Secure`, `HttpOnly` y `SameSite=Strict`, con caducidad.
  - Token CSRF en todos los POST salvo el login, que solo acepta JSON y rechaza un `Origin` ajeno.
  - `Content-Security-Policy: default-src 'self'` y solo DOM seguro (`textContent`) en el navegador.
  - Errores genéricos al cliente; el detalle va al registro.
- **Hora siempre en Europe/Madrid**, aunque el servidor de Render funcione en UTC.

## Supuestos

| Duda | Supuesto |
| --- | --- |
| ¿Pueden tocarse dos visitas? | Sí: los intervalos son `[inicio, fin)` |
| Visitas que ocupan horario | Programadas y Completadas. Las Canceladas lo liberan |
| Misma obra | Sin mínimo: dos visitas seguidas en la misma obra pueden ir pegadas (los 30 min son "entre obras diferentes") |
| Primera visita del día | Sale de la sede y puede empezar a las 08:00. Si se indica un desplazamiento, la salida no puede ser antes de las 08:00 |
| Cancelar | Nunca se bloquea. La visita siguiente cambia de origen y queda marcada "Revisar desplazamiento" |
| Completar | Solo el técnico de la visita y a partir de su hora de inicio. Comentario de 10 a 2.000 caracteres; motivo de cancelación de 5 a 2.000 |

## Uso de la IA

He trabajado con Claude Code a partir de un análisis funcional que revisé yo, en fases cerradas:
- **Tests primero:** se escribían antes del código y se comprobaba que fallaban.
- **Puerta por fase:** cada fase tenía su puerta automática (`composer verify`) y mi aprobación antes de pasar a la siguiente.
- **Tests de seguridad comprobados al revés:** se quitaba a propósito la protección (el `FOR UPDATE`, el token CSRF, la regeneración del id de sesión) para ver que el test fallaba.

**Casos corregidos:**
- **`CHECK` de fin de jornada:** el análisis generado lo calculaba con `TIME + INTERVAL`, que da la vuelta a medianoche (16:00 + 540 min = 01:00 y pasaba). Se calculó en minutos.
- **Script de creación de bases:** una variable de PowerShell chocaba con un parámetro, porque PowerShell no distingue mayúsculas, y `psql` intentó entrar con el usuario de Windows. Lo detecté al ejecutarlo.
- **Regla de 30 min:** al revisar el alcance contra el enunciado vi que la aplicación era más estricta que él. Exigía 30 min también desde la sede y dentro de la misma obra, así que ninguna visita podía empezar a las 08:00. Se corrigió antes de la entrega.

## API

Todas las respuestas son JSON. Los errores tienen la forma `{"errores": [{"regla", "mensaje", "campo"?}]}`. Las reglas son `CAMPOS`, `SOLAPE`, `DESPLAZAMIENTO`, `JORNADA`, `PERMISO`, `ESTADO`, `NO_ENCONTRADO` e `INTERNO`.

| Método y ruta | Quién | Respuestas |
| --- | --- | --- |
| `POST /api/login`, `POST /api/logout`, `GET /api/yo` | Público / con sesión | 200 con `usuario` y `csrf`; 401 |
| `GET /api/tecnicos`, `GET /api/obras` | Planificador | 200; 403 |
| `GET /api/visitas?tecnico_id=&fecha=&estado=` | Ambos (el técnico solo ve las suyas) | 200; 422 |
| `GET /api/agenda?tecnico_id=&fecha=` | Ambos (el técnico, solo la suya) | 200; 403; 422 |
| `POST /api/visitas/validar` | Planificador | 200 con `valida`, `origen`, `siguiente` y `errores`; no guarda nada |
| `POST /api/visitas` | Planificador | 201; 403; 422 |
| `POST /api/visitas/{id}/completar` con `comentario` | Técnico (las suyas) | 200; 403; 404; 409; 422 |
| `POST /api/visitas/{id}/cancelar` con `motivo` | Ambos (el técnico, las suyas) | 200; 403; 404; 409; 422 |

Otros casos:
- sin sesión, 401;
- JSON mal formado o sin `Content-Type: application/json`, 400;
- POST sin la cabecera `X-CSRF-Token`, salvo el login, 403.

## Despliegue

- **Contenedor:** `Dockerfile` (`php:8.3-apache`), carpeta `docker/` y `render.yaml`. Ninguno contiene secretos: `DATABASE_URL` y `DEMO_PASSWORD` se configuran en el panel de Render.
- **Base de datos en Neon:** se carga con los mismos `db/migrations/001_esquema.sql` y `db/seed.sql`, usando el usuario propietario. Los usuarios demo los crea el contenedor al arrancar.
