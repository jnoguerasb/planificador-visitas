# CLAUDE.md — Planificador de visitas a obras

Este archivo es la guía de trabajo para Claude Code en este repositorio. Se lee al empezar cada sesión y se respeta en todas las fases.

## 0. Fuentes de verdad y prioridad

1. **Premisas de seguridad** (sección 11 de `docs/analisis.md`, resumidas en el apartado 3 de este archivo). Prevalecen sobre todo lo demás.
2. **Este archivo** (`CLAUDE.md`): forma de trabajar, fases, puertas de validación.
3. **`docs/analisis.md`**: análisis funcional completo. Reglas de negocio (sección 4), modelo de datos (5), pantallas y API (6), arquitectura (7), casos de prueba (8), supuestos y puntos a revisar (10).

Si dos fuentes se contradicen, o algo no está definido, **pregunta antes de implementar**. No inventes reglas de negocio.

## 1. Reglas de trabajo por fases (obligatorias)

El proyecto se construye en **fases cerradas**. Una fase solo termina cuando pasa su puerta de validación y el usuario la aprueba.

1. **Trabaja solo en la fase actual.** No adelantes código de fases posteriores, aunque parezca cómodo.
2. **Tests primero cuando la fase lo indique** (dominio y servicios): escribe los tests del alcance, comprueba que fallan y después implementa.
3. **Al terminar la fase, ejecuta la puerta de validación completa** (apartado 4 + los criterios de la fase). Pega en tu respuesta el resumen real de la salida de los comandos, no una paráfrasis.
4. **Si algo falla, se corrige dentro de la fase.** Nunca:
   - borres, debilites o marques como `skipped`/`incomplete` un test para que pase;
   - cambies un valor esperado de un test sin explicar por qué la especificación lo justifica y sin aprobación del usuario;
   - desactives una comprobación de `composer verify`.
5. **Informe de fase** al terminar, con este formato:
   - Qué se ha hecho (archivos creados o modificados).
   - Resultado de la puerta: comandos ejecutados y resultado (tests pasados/total).
   - Desviaciones respecto al análisis y por qué.
   - Pasos de **validación manual** para el usuario, si la fase los tiene.
   - Dudas o riesgos abiertos.
6. **Para y espera.** Después del informe, no empieces la siguiente fase hasta que el usuario responda explícitamente "OK fase N".
7. **Cierre de fase tras la aprobación:** un commit con mensaje `fase N: <resumen>` y una etiqueta `git tag fase-N`. Nunca hagas commit de una fase no aprobada.
> **Cambio aprobado por el usuario el 30/10/2026 para las fases 6, 7, 8 y la parte de Claude Code de la 9:** se desarrollan seguidas, sin parar entre ellas. En cada fase se sigue ejecutando la puerta automática (`composer verify` y los criterios automatizables) y se hace un commit `fase N: <resumen>`, pero **sin etiqueta**. Las validaciones manuales de todas ellas se hacen juntas al final; las etiquetas `fase-N` se ponen cuando el usuario las aprueba. Mientras tanto, el usuario avanza con su parte de la Fase 9 siguiendo `docs/guia-despliegue.md`.

8. **Una fase aprobada no se reabre en silencio.** Si una fase posterior obliga a cambiar código de una anterior, avisa, explica el motivo y vuelve a pasar los tests de todas las fases.

## 2. Stack y convenciones

- **Backend:** PHP 8.3 sin framework, PDO, `declare(strict_types=1);` en todos los archivos, autoload PSR-4 con Composer (`App\` → `src/`).
- **Base de datos:** PostgreSQL 16. SQL estándar y portable a MySQL cuando sea posible (ver sección 5 del análisis).
- **Frontend:** HTML, CSS y JavaScript vanilla, sin build ni dependencias.
- **Tests:** PHPUnit 11 (única dependencia de desarrollo). Cobertura con `pcov` 1.0.12 (DLL de PECL para PHP 8.3 NTS x64) en `C:\php83\ext`, cargado con `pcov.enabled=0` y activado solo al medir: `php -d pcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text`.
- **Entorno de desarrollo: local en Windows, sin Docker.** El equipo de desarrollo no permite virtualización (ni Docker Desktop ni WSL2). Por eso, y esto prevalece sobre la sección 7 del análisis:
  - PHP 8.3 portable en `C:\php83`, independiente del PHP 8.1 de XAMPP, que no se toca. Extensiones: `pdo_pgsql`, `curl`, `mbstring`, `openssl`, `zip`.
  - PostgreSQL 16 instalado como servicio de Windows en `localhost:5432`. La base `planificador` es para desarrollo y `planificador_test` para tests; ambas se crean con `scripts/crear_bases.ps1`.
  - Servidor de desarrollo `php -S localhost:8081 -t public` con `display_errors=0`, lanzado por `scripts/servidor.ps1`. No se usa el Apache de XAMPP. El puerto es 8081 porque el 8080 lo ocupa otro programa del equipo (MiniTool ShadowMaker).
  - `scripts/entorno.ps1` (se carga con `. .\scripts\entorno.ps1`) antepone `C:\php83`, el `bin` de PostgreSQL y `C:\Program Files\Git\bin` al `PATH` **solo en la sesión actual** y carga las variables de `.env`. Sin librerías de terceros.
  - Dos usuarios de base de datos: `app_planificador` (aplicación, `DATABASE_URL`) y `planificador_admin` (propietario de las bases, migraciones y seed, `DATABASE_ADMIN_URL`). Ninguno es superusuario. Los crea `scripts/crear_bases.ps1` con las contraseñas de `.env`.
  - Los tests de integración usan `DATABASE_URL_TEST` y `DATABASE_ADMIN_URL_TEST`, nunca las de desarrollo, y se niegan a ejecutarse si la base no acaba en `_test`.
  - Finales de línea LF forzados con `.gitattributes`, para que el código funcione igual en el contenedor Linux de producción.
- **Entorno de producción:** contenedor Docker (`php:8.3-apache`) en Render con PostgreSQL en Neon. El `Dockerfile` se crea en la Fase 9 y se valida con el build de Render.
- **Idioma:** nombres de clases, métodos, variables, tablas y mensajes en español, como en el análisis (`ValidadorVisita`, `desplazamiento_min`). Comentarios en español, breves y solo donde aporten.
- **Mantenibilidad:** la persona que mantendrá el código conoce HTML, CSS, JS, PHP y MySQL. Prioriza código simple y explícito frente a abstracciones. Una clase, una responsabilidad.
- **Zona horaria:** `Europe/Madrid` siempre, sea cual sea la del servidor. La hora actual sale de `App\Reloj`, y `Database` fija la misma zona en cada conexión de PostgreSQL (`CURRENT_DATE`, marcas de tiempo).
- **Constantes de negocio** en un único lugar (`src/Reglas.php`): `JORNADA_INICIO = 480`, `JORNADA_FIN = 1020`, `DESPLAZAMIENTO_MINIMO = 30`, `DESPLAZAMIENTO_MAXIMO = 240`, `DURACION_MINIMA = 15`, `DURACION_MAXIMA = 540`. Los puntos R1 y R2 del análisis (misma obra y salida desde la sede) se resolvieron el 02/11/2026 según el enunciado, con aprobación del usuario: el mínimo de 30 solo se exige entre obras diferentes; desde la sede o en la misma obra es 0. Toda la regla está en `Reglas::desplazamientoMinimo`.

### Comandos estándar

Se ejecutan en PowerShell desde la raíz del proyecto, después de cargar el entorno: `. .\scripts\entorno.ps1` y luego `composer <script>`. Composer debe ejecutarse con PHP 8.3 (`composer.json` exige `"php": "^8.3"`).

| Script | Qué hace |
| --- | --- |
| `composer lint` | `php -l` sobre todos los `.php` de `src/`, `public/`, `bin/` y `tests/` |
| `composer test:unit` | Suite `unit` (sin base de datos) |
| `composer test:integration` | Suite `integration` (contra `planificador_test`) |
| `composer test:api` | Suite `api` (HTTP contra un servidor PHP de pruebas) |
| `composer test` | Las tres suites |
| `composer check:security` | Ejecuta `scripts/check-security.sh` con el `bash` de Git for Windows |
| `composer verify` | `lint` + `test` + `check:security`. Es la puerta mínima de todas las fases |

## 3. Premisas de seguridad (resumen operativo)

Texto completo en la sección 11 de `docs/analisis.md`. En este proyecto se concretan así:

1. **Entradas:** toda entrada externa es no confiable. Se valida siempre en servidor (tipo, formato, longitud, rango, lista blanca). Lo que no cumple se **rechaza**; el servidor nunca corrige valores. Campos no esperados en el JSON se rechazan.
2. **Base de datos:** solo sentencias preparadas, `PDO::ATTR_EMULATE_PREPARES = false`, `PDO::ERRMODE_EXCEPTION`. Prohibido concatenar datos en SQL. El usuario de la aplicación (`app_planificador`) solo tiene `SELECT`, `INSERT`, `UPDATE`; las migraciones se ejecutan con otro usuario.
3. **XSS:** en JS solo `textContent`, `createElement`, `setAttribute` con valores controlados. Prohibidos `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, `new Function` con datos. Cabecera `Content-Security-Policy: default-src 'self'`.
4. **CSRF:** token `bin2hex(random_bytes(32))` en sesión, enviado en la cabecera `X-CSRF-Token`, comparado con `hash_equals`, en **todos** los POST **salvo el login**. El token se genera al iniciar sesión y se devuelve en la respuesta del login. Excepción aprobada por el usuario el 30/10/2026 (motivo en la sección 11.4 del análisis). El login lo compensa así: solo acepta `Content-Type: application/json` (una web ajena no puede enviarlo sin CORS, que no se habilita), rechaza con 403 una cabecera `Origin` de otro sitio y la cookie es `SameSite=Strict`. Ningún GET cambia estado.
5. **Sesión:** cookie `Secure`, `HttpOnly`, `SameSite=Strict` en todos los entornos; `session.use_strict_mode=1`; solo el id de sesión en la cookie; `session_regenerate_id(true)` al autenticar; caducidad por 30 min de inactividad y 8 h máximas; logout con `session_destroy()` y borrado de cookie.
6. **Autorización:** el router tiene una tabla ruta → roles permitidos y **deniega por defecto**. `Permisos` comprueba además la propiedad de la visita. Mínimo privilegio.
7. **Secretos:** `password_hash(PASSWORD_DEFAULT)` / `password_verify`. Ningún secreto en el código ni en el repositorio: `DATABASE_URL`, `DATABASE_ADMIN_URL` (y sus `_TEST`), `DEMO_PASSWORD`, `APP_ENV` solo por variables de entorno. `.env` en `.gitignore`; `.env.example` sin valores reales.
8. **SSRF:** la aplicación no hace peticiones salientes. No añadas ninguna.
9. **Archivos:** sin subida de archivos. El router no traduce rutas a ficheros. El servidor web sirve solo `public/` (Apache en producción, `php -S ... -t public` en desarrollo).
10. **Errores:** `display_errors=Off`. Manejador global: detalle a `error_log`, respuesta 500 con `{"errores":[{"regla":"INTERNO","mensaje":"Ha ocurrido un error. Inténtalo de nuevo."}]}`. Login siempre con "Usuario o contraseña incorrectos".

`scripts/check-security.sh` falla (código distinto de 0) si encuentra:
- en `public/js/`: `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval(`, `new Function`;
- en `src/` y `public/*.php`: cadenas SQL con interpolación o concatenación de variables (`"SELECT ... $`, `'... ' . $` dentro de llamadas a `query`/`prepare`/`exec`);
- en cualquier archivo versionado salvo `.env.example`: patrones de secretos (`password\s*=\s*['"][^'"]+`, `DATABASE_URL=postgres://[^ ]+:[^@ ]+@`, claves tipo `sk-`, `AKIA`);
- `.env` rastreado por git (`git ls-files .env`);
- `display_errors` activado en la configuración versionada (scripts de arranque, `.ini`, `Dockerfile`).

## 4. Puerta de validación común (todas las fases)

Una fase solo puede darse por terminada si se cumple todo:

- [ ] Con el entorno cargado, `php -v` muestra 8.3, `pg_isready -h localhost -p 5432` responde *accepting connections* y existen `planificador` y `planificador_test`.
- [ ] `composer verify` termina con código 0.
- [ ] Ningún test omitido, marcado como incompleto o eliminado respecto a la fase anterior.
- [ ] Los tests de **todas las fases anteriores** siguen pasando.
- [ ] Los criterios de aceptación propios de la fase (apartado 5) se cumplen uno por uno.
- [ ] Informe de fase entregado y validación manual descrita.
- [ ] Aprobación explícita del usuario ("OK fase N").

## 5. Fases

> El orden difiere ligeramente del plan de la sección 9 del análisis: la seguridad transversal (autenticación, CSRF, permisos) va **antes** de los endpoints. La premisa 11.6 obliga a que cada endpoint compruebe autenticación y autorización, así que construirlos sin ella obligaría a rehacerlos y dejaría un estado intermedio inseguro.

### Fase 0 — Entorno base

**Objetivo:** repositorio que arranca y ejecuta una suite de tests vacía.

Requisito previo (lo instala el usuario siguiendo las indicaciones de Claude Code): PHP 8.3 en `C:\php83`, PostgreSQL 16 como servicio, Composer y Git for Windows.

Tareas:
- `git init` y `.gitattributes` con `eol=lf` para `*.php`, `*.sh`, `*.sql`, `*.js`, `*.css`, `*.html`, `*.md`, `*.json`, `*.xml`, `*.yml` (los `*.ps1` con `eol=crlf`).
- Estructura de carpetas de la sección 7 del análisis (vacía salvo lo necesario), con `tests/Unit`, `tests/Integration`, `tests/Api` y `tests/Support`.
- `scripts/entorno.ps1`: PATH de sesión y carga de `.env` (apartado 2); falla con mensaje claro si falta PHP 8.3, PostgreSQL o `.env`.
- `scripts/crear_bases.ps1`: crea `planificador` y `planificador_test` si no existen (idempotente), con el superusuario cuya contraseña se pide por consola o viene de `.env`; nunca escrita en el script.
- `scripts/servidor.ps1`: `php -d display_errors=0 -S localhost:8081 -t public` (puerto cambiable con `-Puerto`).
- `composer.json` con `"php": "^8.3"`, PHPUnit 11, autoload y los scripts del apartado 2.
- `phpunit.xml` con las suites `unit`, `integration`, `api` y `failOnRisky`, `failOnWarning`, `failOnEmptyTestSuite=false`.
- `scripts/check-security.sh` completo desde ahora.
- `.gitignore` (`.env`, `vendor/`, `coverage/`), `.env.example` con nombres de variables (`DATABASE_URL`, `DATABASE_URL_TEST`, `DATABASE_ADMIN_URL`, `DATABASE_ADMIN_URL_TEST`, `DEMO_PASSWORD`, `APP_ENV`, `PG_BIN`) y valores de ejemplo no reales.
- `README.md` mínimo con los requisitos y el arranque en Windows.
- Un test trivial `tests/Unit/EntornoTest.php` que compruebe que PHP es 8.3 y que `pdo_pgsql` está cargado.

Criterios de aceptación:
- Desde un clon limpio: `. .\scripts\entorno.ps1`, `composer install`, `.\scripts\crear_bases.ps1` y `composer verify` pasan.
- Con `.\scripts\servidor.ps1` en marcha, `http://localhost:8081` responde (aunque sea una página provisional).
- `check-security.sh` falla si se añade a propósito un `innerHTML` a un JS de prueba (demostrarlo y revertirlo).

Validación manual: el usuario clona el repo en otra carpeta, sigue solo el README y comprueba que arranca.

### Fase 1 — Base de datos y datos de ejemplo

**Objetivo:** esquema completo con todas sus restricciones y datos de ejemplo cargables.

Tareas:
- `db/migrations/001_esquema.sql` según la sección 5 del análisis: `sede`, `tecnicos`, `obras`, `usuarios`, `visitas`, índices y todos los `CHECK` (incluido `desplazamiento_min BETWEEN 0 AND 240`, con 0 desde la sede o en la misma obra tras resolver R1 y R2, y el de fin de jornada calculado en minutos, nunca con `TIME + INTERVAL`).
- Creación del rol `app_planificador` con `GRANT SELECT, INSERT, UPDATE` sobre las tablas y `USAGE` sobre las secuencias; sin `DELETE`.
- `db/seed.sql`: sede, 4 técnicos, 6 obras en 4 municipios, unas 12 visitas con fechas relativas a `CURRENT_DATE` (incluida una Completada con comentario, una Cancelada con motivo y casos límite de desplazamiento). Sin usuarios.
- `bin/crear_usuarios_demo.php`: crea `planificador@demo.local` y un usuario por técnico con `DEMO_PASSWORD`; idempotente; falla con mensaje claro si la variable no existe.
- `tests/Support/BaseDatosTest.php`: clase base que recrea el esquema y el seed en `planificador_test` antes de cada clase de test.

Tests (suite `integration`, escritos antes de dar la fase por buena):
- El seed carga el número esperado de filas por tabla.
- Cada `CHECK` rechaza su caso: duración 0 y 541, desplazamiento negativo y 241, inicio 07:59, fin 17:01, inicio 16:00 con 540 min (el caso de la vuelta a medianoche), estado inválido, Completada sin comentario, Cancelada sin motivo, origen `SEDE` con `origen_visita_id`, rol `TECNICO` sin `tecnico_id`.
- Conectado como `app_planificador`, un `DELETE` sobre `visitas` falla por permisos.
- `crear_usuarios_demo.php` es idempotente y guarda hashes, nunca la contraseña en claro.

- `scripts/recrear_bd.ps1`: borra y recrea `planificador`, ejecuta la migración y el seed con el usuario propietario y lanza `bin/crear_usuarios_demo.php`.

Criterios de aceptación: todos los tests anteriores en verde; `.\scripts\recrear_bd.ps1` deja la base de desarrollo cargada de nuevo desde cero.

### Fase 2 — Dominio puro (tests primero)

**Objetivo:** todas las reglas de negocio en clases sin base de datos, cubiertas por tests.

Clases: `Reglas`, `Horas` ("10:30" ↔ 630), `ValidadorVisita`, `CalculadoraAgenda`, `Permisos`, y los objetos de valor que necesites (`Visita`, `ResultadoValidacion`, `ErrorRegla`).

Comportamiento obligatorio (sección 4 del análisis):
- Intervalos semiabiertos; horas en minutos.
- Validación de campos primero: si hay errores `CAMPOS`, se devuelven sin evaluar el resto.
- Tres comprobaciones expresadas como condiciones que se deben cumplir: **Solape** (contra todas las activas), **Llegada** (contra la anterior o la sede) y **Salida** (contra la siguiente). Más la jornada.
- Visitas activas = Programadas y Completadas. Las Canceladas se ignoran.
- Se devuelven todos los errores; si hay solape con una visita, no se informa además desplazamiento con esa misma visita.
- Mensajes exactos de la tabla de reglas del análisis, incluido "El desplazamiento mínimo es 30 min".
- El origen se calcula siempre; el valor que envíe el cliente se ignora.

Tests (suite `unit`), uno por caso, con el número del análisis en el nombre del test:
- Casos 1–20 de la sección 8 y ejemplos 1–9 de la sección 4.
- Reglas de `Permisos` de los casos 21–28 (sin HTTP).
- `CalculadoraAgenda`: huecos con y sin visitas, el desplazamiento ocupa tiempo antes de cada visita, día completo libre, día completo ocupado.
- Límites exactos (30 min justos, fin a las 17:00 justas, salida de la sede a las 08:00 justas).

Criterios de aceptación: suite `unit` en verde; cobertura de líneas de `ValidadorVisita`, `CalculadoraAgenda` y `Permisos` ≥ 90 % (`phpunit --coverage-text`).

Validación manual: el usuario revisa la lista de nombres de test (`phpunit --testdox`) y confirma que cubre los casos que espera.

### Fase 3 — Persistencia y servicios

**Objetivo:** operaciones completas contra base de datos, transaccionales y seguras ante concurrencia.

Clases: `Database` (PDO desde `DATABASE_URL`), `VisitaRepository`, `UsuarioRepository`, `CatalogoRepository` (técnicos, obras, sede), `VisitaService` con `programar`, `validar` (sin guardar), `completar`, `cancelar`, `agenda`.

Comportamiento obligatorio:
- Cada operación de escritura en una transacción que empieza con `SELECT ... FROM tecnicos WHERE id = ? FOR UPDATE`.
- `programar`: inserta la visita y actualiza origen y desplazamiento de la siguiente en la misma transacción.
- `cancelar`: motivo obligatorio; reasigna el origen de la visita que dependía de la cancelada y marca `revisar_desplazamiento`; nunca se bloquea por ello.
- `completar`: comentario obligatorio; solo desde la hora de inicio.
- `validar`: misma lógica que `programar`, sin escribir nada.
- Todas las consultas con sentencias preparadas.

Tests (suite `integration`, escritos primero):
- Casos 18 y 19 de la sección 8 de punta a punta en base de datos.
- `validar` no deja filas nuevas.
- Un error de validación hace rollback completo (ni la nueva ni la actualización de la siguiente).
- Completar y cancelar registran autor y fecha; transiciones inválidas dan `ESTADO`.
- Concurrencia: dos conexiones intentan programar visitas incompatibles para el mismo técnico; solo una se guarda.

Criterios de aceptación: suites `unit` e `integration` en verde.

### Fase 4 — Seguridad transversal y router

**Objetivo:** la infraestructura HTTP segura sobre la que irán los endpoints.

Clases y archivos: `Auth` (login, sesión, caducidad, logout, usuario actual), `Csrf`, `Entrada` (lectura y validación estricta del JSON), `Respuesta` (JSON, cabeceras de seguridad), `ManejadorErrores`, `public/index.php` con la tabla de rutas → roles y denegación por defecto.

Endpoints de esta fase: `POST /api/login` (sin token CSRF, devuelve el token nuevo), `POST /api/logout`, `GET /api/yo`. Sin rutas solo para tests.

JSON mal formado o `Content-Type` distinto de `application/json`: **400** con la regla `CAMPOS`. Datos legibles pero no válidos: 422.

Cabeceras en todas las respuestas: `Content-Security-Policy: default-src 'self'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options: DENY`.

Tests:
- `unit`: `Csrf` (token válido, ausente, alterado), `Entrada` (campos extra, tipos, JSON mal formado), `ManejadorErrores` (una excepción con rutas o SQL en su mensaje produce un 500 con el mensaje genérico, sin esos detalles en el cuerpo, y el detalle va al registro; sin forzar errores en el servidor).
- `api` (servidor `php -S localhost:8099 -t public` arrancado por la clase base con `DATABASE_URL` de test; usar el host `localhost` para que el cliente acepte la cookie `Secure`):
  - login correcto sin token CSRF: 200, devuelve un token, cookie con `Secure`, `HttpOnly`, `SameSite=Strict`, id de sesión distinto al previo;
  - login con `Origin` de otro sitio: 403; con `Content-Type` que no es JSON: 400;
  - login incorrecto con email existente y con email inexistente: mismo 401 y mismo mensaje;
  - POST con sesión pero sin token CSRF o con token alterado (logout): 403;
  - `GET /api/yo` sin sesión: 401; tras logout: 401;
  - cabeceras de seguridad presentes en todas las respuestas, también en las de error; ruta inexistente: 404.

Criterios de aceptación: todas las suites en verde; `check:security` en verde.

### Fase 5 — API de negocio

**Objetivo:** todos los endpoints de la sección 6 del análisis, protegidos.

Endpoints: `GET /api/tecnicos`, `GET /api/obras`, `GET /api/visitas`, `GET /api/agenda`, `POST /api/visitas/validar`, `POST /api/visitas`, `POST /api/visitas/{id}/completar`, `POST /api/visitas/{id}/cancelar`. Formato de error `{"errores":[{"regla","mensaje"}]}`.

Tests (suite `api`):
- Cada endpoint: caso correcto, sin sesión (401), rol no permitido (403), entrada inválida (422).
- Casos 25–30 de la sección 8.
- Un técnico que pide `tecnico_id` de otro recibe solo lo suyo.
- `POST /api/visitas` con desplazamiento 20 devuelve 422 con "El desplazamiento mínimo es 30 min" y no guarda nada.
- `validar` devuelve el origen calculado y los errores, sin persistir.

Criterios de aceptación: todas las suites en verde.

Validación manual: el usuario ejecuta los ejemplos de `curl` que añadas al README (visita inválida → 422; técnico sobre visita ajena → 403).

### Fase 6 — Interfaz del planificador

**Objetivo:** vista Login y vista Planificación completas.

Archivos: `public/index.html`, `public/css/estilos.css`, `public/js/api.js`, `public/js/login.js`, `public/js/planificacion.js`, `public/js/agenda.js`.

Contenido (sección 6 del análisis): formulario con desplazamiento (numérico, `min=30`, `max=240`, valor 30) y "desplazamiento hasta la siguiente" cuando aplique; panel de agenda con la franja 08:00–17:00 y la visita propuesta en verde o rojo usando `POST /api/visitas/validar` con 400 ms de espera; errores del servidor junto a su campo o en la lista de errores; filtros y listado; cancelar con motivo obligatorio.

Reglas: solo DOM seguro (apartado 3); ningún dato de negocio decidido en el cliente (el JS muestra lo que dice el servidor); la información de la agenda también en texto, no solo con color; diseño responsive.

Tests y comprobaciones:
- `check:security` en verde (sin `innerHTML`, `eval`, etc.).
- Todas las suites anteriores en verde.
- Lista de comprobación manual en el informe, como mínimo: login y logout; programar una visita válida; provocar `SOLAPE`, `DESPLAZAMIENTO`, `JORNADA` y desplazamiento 20 y ver el mensaje correcto; la previsualización coincide con el resultado al guardar; filtros; cancelar con y sin motivo; un comentario con `<script>` se ve como texto.

Criterios de aceptación: comprobaciones automáticas en verde y lista manual validada por el usuario.

### Fase 7 — Interfaz del técnico

**Objetivo:** vista Mi agenda.

Archivo: `public/js/mi-agenda.js` (reutiliza `agenda.js` y `api.js`).

Contenido: día actual con navegación anterior/siguiente, tarjetas por visita, Completar (solo si es Programada y ha llegado su hora) y Cancelar (si es Programada), ambos con ventana de texto obligatorio y contador. Diseño pensado para móvil.

Comprobaciones: `check:security` y todas las suites en verde. Lista manual: entrar como `ana@demo.local`; ver solo sus visitas; completar con comentario corto (error) y válido; intentar completar una visita futura (error); cancelar sin motivo (error) y con motivo; comprobar en la vista del planificador que aparecen comentario, motivo y autor.

Criterios de aceptación: comprobaciones automáticas en verde y lista manual validada por el usuario.

### Fase 8 — Documentación y revisión de seguridad

**Objetivo:** entrega completa y revisada.

Tareas:
- `README.md`: requisitos (PHP 8.3, PostgreSQL 16, Composer, Git for Windows), arranque en menos de 10 minutos, usuarios demo, comandos de test, ejemplos de `curl`, aviso de arranque en frío en Render.
- `DECISIONES.md` (una página como máximo): qué se ha hecho y qué queda pendiente; decisiones principales; supuestos (sección 10 del análisis, incluidos R1 y R2); uso de la IA con al menos un caso corregido (las tres correcciones de la sección 9 del análisis y las que surjan).
- Repaso de la tabla 11.11 del análisis punto por punto, con el resultado en el informe.

Criterios de aceptación: `composer verify` en verde; un clon limpio arranca siguiendo solo el README en menos de 10 minutos (cronometrado, con los requisitos ya instalados); `git log` sin `.env` ni secretos en ningún commit (`git log -p | grep` de los patrones de `check-security.sh`).

### Fase 9 — Despliegue (requiere acciones del usuario)

**Objetivo:** aplicación publicada en Render con base de datos en Neon.

Claude Code prepara: `Dockerfile` de producción (`php:8.3-apache`, `pdo_pgsql`, `DocumentRoot` en `public/`, `display_errors=Off`, sin `pcov` ni dependencias de desarrollo, `composer install --no-dev`), `render.yaml` sin secretos, una lista de diferencias entre desarrollo (Windows, `php -S`) y producción (Linux, Apache) revisada punto por punto (mayúsculas en rutas, finales de línea, cabeceras, cookie `Secure` tras el proxy de Render), instrucciones paso a paso en el README para crear la base en Neon, ejecutar migración y seed con un usuario administrador, crear el rol `app_planificador` y configurar `DATABASE_URL`, `DEMO_PASSWORD` y `APP_ENV=production` en Render.

El usuario: crea las cuentas, introduce los secretos en los paneles y lanza el despliegue. **Claude Code nunca pide ni maneja las credenciales reales.**

Criterios de aceptación: el build de Docker en Render termina sin errores; la URL pública permite iniciar sesión con ambos perfiles; las cabeceras de seguridad y los atributos de la cookie se ven en el navegador; una visita inválida devuelve 422 en producción.

## 6. Qué no hacer

- No añadir frameworks ni librerías de PHP o JS (solo PHPUnit en desarrollo) sin aprobación.
- No cambiar el motor de base de datos, los nombres de tablas o los códigos de error del análisis.
- No implementar funcionalidades de la lista de pendientes (sección 10 del análisis) por tu cuenta. R1 y R2 ya están resueltos (02/11/2026, con aprobación).
- No borrar datos: las visitas nunca se eliminan, solo cambian de estado.
- No mostrar en la interfaz ni devolver en la API detalles técnicos de errores.
