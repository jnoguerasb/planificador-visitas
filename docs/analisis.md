# Planificador de visitas a obras — Análisis funcional y plan de trabajo

Oct 29, 2026 · @Jordi

## 1. Resumen y decisión de stack

La propuesta es una aplicación web en **PHP 8.3 sin framework + PostgreSQL**, con frontend en HTML, CSS y JavaScript vanilla que consume una pequeña API JSON. Se centra en hacer muy bien los cinco requisitos funcionales, con la validación de reglas en el servidor y cubierta por tests.

Sobre el enunciado se añaden tres ampliaciones: **tiempo de desplazamiento por visita** (desde la sede o desde la visita anterior), **perfil Técnico** que ve solo su agenda y completa o cancela con texto obligatorio, y **agenda visible** al seleccionar técnico. La autenticación usa sesiones nativas de PHP, sin librerías externas.

**Por qué este stack.** La persona que mantendrá el código domina HTML, CSS, JS, PHP y MySQL. Un proyecto en Next.js + TypeScript puntuaría algo más en la prueba, pero sería difícil de mantener para ella. PHP con PDO y SQL estándar es código que puede leer y modificar desde el primer día.

**Por qué PostgreSQL y no MySQL.** El enunciado lo recomienda y Neon ofrece hosting gratuito; el SQL de este proyecto es prácticamente idéntico en ambos motores. Se escribirá SQL portable y se documentarán las 3 o 4 diferencias (tipo `SERIAL` frente a `AUTO_INCREMENT`, `CHECK`, aritmética de horas) para que migrar a MySQL sea trivial si la empresa lo prefiere.

| Pieza | Elección | Motivo |
| --- | --- | --- |
| Backend | PHP 8.3, PDO, sin framework | Conocido por el mantenedor, cero curva de aprendizaje |
| Base de datos | PostgreSQL 16 (Neon en producción) | Recomendado en la prueba, gratuito, SQL portable a MySQL |
| Frontend | HTML + CSS + JS vanilla (fetch) | Sin build, sin dependencias, fácil de tocar |
| Tests | PHPUnit sobre el validador de reglas | Las reglas son el núcleo evaluado |
| Local | Docker Compose (PHP + Postgres) | Arranque en menos de 10 minutos con un solo comando |
| Publicación | Render (Docker) + Neon | Planes gratuitos, despliegue desde GitHub |
| Secretos | Variables de entorno, `.env` en `.gitignore`, `.env.example` versionado | Requisito: no subir claves |

## 2. Análisis del enunciado y de las ampliaciones

El problema real es evitar dos errores de la hoja de cálculo actual: técnicos en dos obras a la vez y técnicos sin tiempo para desplazarse. Sobre el enunciado se añaden tres ampliaciones pedidas por negocio (A1–A3), que convierten el tiempo de desplazamiento en un dato real de cada visita y dan acceso a los técnicos.

| Id | Requisito | Origen | Prioridad | Cómo se cubre |
| --- | --- | --- | --- | --- |
| RF1 | Técnicos y obras | Enunciado | Imprescindible | Tablas + script de datos de ejemplo (4 técnicos, 6 obras en varios municipios, sede de la empresa) |
| RF2 | Programar visita indicando obra, técnico, fecha, hora y duración; decir qué regla falla | Enunciado | Imprescindible | Formulario + `POST /api/visitas` con errores por regla |
| RF3a | Sin solapes del mismo técnico | Enunciado | Imprescindible | Validador en servidor |
| RF3b | 30 min de desplazamiento entre obras distintas | Enunciado | Imprescindible | Validador; 30 min pasa a ser el **mínimo** (ver A1) |
| RF3c | Dentro de la jornada 08:00–17:00 | Enunciado | Imprescindible | Validador en servidor + `CHECK` en BD |
| RF4 | Cancelar: libera horario, sigue visible como Cancelada | Enunciado | Imprescindible | Cambio de estado con motivo, nunca borrado |
| RF5 | Listado ordenado por fecha y hora, filtrable por técnico y fecha | Enunciado | Imprescindible | Pantalla del planificador + `GET /api/visitas` |
| A1 | Tiempo de desplazamiento por visita, desde la sede o desde la visita anterior | Ampliación | Alta | Campo `desplazamiento_min` con origen calculado por el servidor |
| A2 | Perfil Técnico: ve solo su agenda, completa con comentario y cancela con motivo | Ampliación | Alta | Login con sesión PHP, roles y permisos en servidor |
| A3 | Agenda del técnico visible al seleccionarlo | Ampliación (antes opcional) | Alta | Panel de agenda diaria en el formulario + `GET /api/agenda` |
| RT1 | BD relacional | Enunciado | Imprescindible | PostgreSQL con migraciones SQL |
| RT2 | Validación en servidor | Enunciado | Imprescindible | Reglas y permisos viven en PHP; el JS solo ayuda |
| RT3 | Sin claves en el repositorio | Enunciado | Imprescindible | `.env` ignorado; contraseñas demo generadas por script desde variable de entorno |
| E1–E4 | Repo, README, explicación, URL | Enunciado | Imprescindible / valorable | Ver sección 9 |

**Impacto en la prueba.** A1–A3 añaden unas 2,5 horas al plan (sección 9). Para no poner en riesgo la entrega, el plan las sitúa después del núcleo: si el tiempo se acaba, lo entregado sigue cumpliendo el enunciado y las ampliaciones quedan documentadas.

**Ambigüedades detectadas** (se resuelven como supuestos en la sección 10):

- Si dos visitas pueden "tocarse" (una acaba a las 10:00 y otra empieza a las 10:00).
- Si la regla de 30 minutos aplica a dos visitas seguidas en la **misma** obra (el enunciado dice "obras diferentes", así que no).
- Cómo conviven los 30 minutos del enunciado con un tiempo de desplazamiento introducido a mano.
- Si el desplazamiento desde la sede cuenta dentro de la jornada de 08:00 a 17:00.
- Qué pasa con el desplazamiento de la visita siguiente cuando se intercala o se cancela una visita.
- Si un técnico puede completar una visita futura o cancelar cualquiera de las suyas.
- Unidad y límites de la duración; fechas pasadas y fines de semana.
- Si se informa solo de la primera regla incumplida o de todas.

## 3. Análisis funcional

Hay dos perfiles que entran con usuario y contraseña: el **Planificador** (oficina, gestiona todas las visitas) y el **Técnico** (solo ve y gestiona las suyas). Todos los permisos se comprueban en el servidor a partir de la sesión, nunca a partir de lo que envía el navegador.

### Entidades

- **Usuario**: email, contraseña (hash), rol (Planificador o Técnico) y, si es técnico, el técnico al que corresponde.
- **Técnico**: persona que visita obras. Datos: nombre, activo (sí/no).
- **Obra**: lugar a visitar. Datos: nombre, dirección, municipio.
- **Sede**: dirección de la empresa, punto de salida de cada técnico al empezar la jornada. Un único registro.
- **Visita**: un técnico en una obra, un día, de una hora a otra, con el tiempo que tarda en llegar. Datos: obra, técnico, fecha, hora de inicio, duración, **desplazamiento en minutos**, **origen del desplazamiento** (Sede o visita anterior), estado, comentario de cierre, motivo de cancelación, y quién y cuándo la creó, completó o canceló.

### Permisos

| Acción | Planificador | Técnico |
| --- | --- | --- |
| Ver listado de visitas | Todas, con filtros | Solo las suyas |
| Ver agenda diaria | De cualquier técnico | Solo la suya |
| Programar visita | Sí | No |
| Completar visita (con comentario) | No | Solo las suyas en estado Programada, a partir de su hora de inicio |
| Cancelar visita (con motivo) | Cualquiera en estado Programada | Solo las suyas en estado Programada |
| Ver comentario y motivo | Todos | Los de sus visitas |

### Estados de una visita

Una visita nace en **Programada** y puede pasar a **Completada** (con comentario obligatorio) o a **Cancelada** (con motivo obligatorio). Ambos son estados finales: no se reactivan, no se editan y no se borran. Las Completadas siguen ocupando su horario (ocurrieron de verdad); las Canceladas lo liberan.

### Casos de uso del Planificador

**CU1 — Consultar visitas.** Ve todas las visitas ordenadas por fecha y hora de inicio (y por técnico en caso de empate). Filtra por técnico, fecha y estado; los filtros se combinan con Y. Cada fila muestra fecha, hora inicio–fin, técnico, obra, desplazamiento y estado. Las Completadas y Canceladas muestran un icono que despliega el comentario o el motivo.

**CU2 — Programar una visita.**

1. Elige técnico y fecha. Al hacerlo, aparece la **agenda de ese técnico para ese día** (CU3).
2. Elige obra, hora de inicio y duración.
3. La aplicación muestra el **origen del desplazamiento** calculado: "Desde la sede" si no hay visita anterior ese día, o "Desde Obra X (acaba 11:00)" si la hay.
4. Introduce el **tiempo de desplazamiento** en minutos. Viene precargado con 30; si escribe un valor menor, el servidor rechaza la visita con el mensaje "El desplazamiento mínimo es 30 min" y lo corrige en el formulario.
5. Si la nueva visita queda **antes de otra ya programada** en obra distinta, aparece un segundo campo: "Desplazamiento hasta la siguiente visita (Obra Y, 12:30)", precargado con el valor que esa visita tenía.
6. El servidor recalcula el origen, valida formato, permisos y reglas (sección 4) y guarda todo en una transacción. Si algo falla, no guarda nada y devuelve todas las reglas incumplidas con mensajes concretos.

**CU3 — Ver la agenda de un técnico.** Al seleccionar técnico y fecha en el formulario, se muestra su jornada de 08:00 a 17:00 como una franja horizontal: visitas en bloques de color, desplazamientos en bloques rayados delante de cada visita y huecos libres en blanco con su duración. Al cambiar hora, duración o desplazamiento en el formulario, la visita propuesta se dibuja en la franja en verde si cabe o en rojo si choca, antes de guardar. La agenda se refresca tras guardar o cancelar.

**CU4 — Cancelar una visita.** En una visita Programada, pulsa Cancelar, escribe el motivo (obligatorio, mínimo 5 caracteres) y confirma. Se registra quién y cuándo. La visita deja de contar para las reglas.

### Casos de uso del Técnico

**CU5 — Iniciar sesión.** Entra con email y contraseña y llega directamente a **Mi agenda**. No ve el formulario de programación ni el listado general.

**CU6 — Consultar mi agenda.** Ve sus visitas de un día (por defecto hoy) con flechas de día anterior y siguiente: hora, obra, dirección, municipio, desplazamiento y origen, y estado. También puede ver la lista de sus próximas visitas.

**CU7 — Completar una visita.** En una visita suya Programada cuya hora de inicio ya ha llegado, pulsa Completar, escribe el comentario sobre el trabajo realizado o los temas tratados (obligatorio, mínimo 10 caracteres, máximo 2.000) y confirma. La visita pasa a Completada.

**CU8 — Cancelar una visita.** En una visita suya Programada, pulsa Cancelar, escribe el motivo (obligatorio) y confirma. El planificador lo verá en su listado con el motivo y el nombre del técnico como autor de la cancelación.

**CU9 — Datos maestros.** Técnicos, obras, sede y usuarios llegan por el script de ejemplo. Su gestión desde pantalla queda pendiente.

## 4. Reglas de negocio y validación

El tiempo de desplazamiento es un dato de cada visita: el que necesita el técnico para **llegar** a ella, desde la sede o desde su visita anterior. Su valor por defecto es 30 minutos y nunca puede ser menor: un valor inferior se **rechaza** (premisa de seguridad 11.1) y el planificador lo corrige en el formulario.

Todas las reglas se evalúan en el servidor, en una clase PHP pura (`ValidadorVisita`) que no toca la base de datos: recibe la visita nueva y las visitas activas (Programadas y Completadas) del mismo técnico ese día, y devuelve la lista de errores. Los intervalos son **semiabiertos** `[inicio, fin)` y las horas se manejan en minutos desde medianoche (10:30 = 630).

### Tiempo de desplazamiento

- El campo viene precargado con **30**. En el formulario es numérico con mínimo 30 y máximo 240, así que el navegador ya avisa antes de enviar.
- El servidor lo valida igualmente, sin fiarse del formulario: si llega vacío, no es un entero o es **menor de 30**, la visita no se guarda y se devuelve el error `CAMPOS` con el mensaje **"El desplazamiento mínimo es 30 min"**. Si es mayor de 240, el error es "El desplazamiento no puede superar 240 min".
- El formulario muestra el mensaje junto al campo para que el planificador lo corrija y vuelva a guardar.
- El servidor nunca modifica el valor recibido: lo que se guarda es exactamente lo que se ha validado (entre el mínimo que corresponda y 240).
- El mismo criterio se aplica al campo "desplazamiento hasta la siguiente".
- **Resuelto el 02/11/2026 según el enunciado (sección 10, R1 y R2):** el mínimo de 30 solo se exige entre visitas a obras diferentes. Desde la sede o en la misma obra el mínimo es 0, así que la primera visita puede empezar a las 08:00. Si se indica un desplazamiento desde la sede, la salida tampoco puede ser antes de las 08:00. El formulario propone el mínimo que corresponda.

### Las tres comprobaciones

Cada comprobación se expresa como la condición que la visita **debe cumplir** para guardarse. Si alguna es falsa, la visita no se guarda y se informa de esa regla.

**1. Solape** (regla a), contra todas las visitas activas del técnico ese día. La nueva debe acabar antes de que empiece la otra o empezar después de que acabe:

```latex
\text{nuevaFin} \le \text{otraInicio} \;\lor\; \text{nuevaInicio} \ge \text{otraFin}
```

**2. Llegada** (regla b y jornada), contra la visita **anterior** (P) o, si no la hay, contra la sede. Tiene que dar tiempo a llegar a la nueva:

```latex
\text{nuevaInicio} \ge \text{anteriorFin} + \text{desplazamiento}_{\text{nueva}}
```

Si el origen es la sede, la salida no puede ser antes de las 08:00: nuevaInicio − desplazamiento de la nueva ≥ 08:00.

**3. Salida** (regla b), contra la visita **siguiente** (S), si la hay. Después de la nueva tiene que dar tiempo a llegar a la siguiente:

```latex
\text{siguienteInicio} \ge \text{nuevaFin} + \text{desplazamiento}_{\text{siguiente}}
```

El desplazamiento de la siguiente es el campo "desplazamiento hasta la siguiente" del formulario (también con mínimo 30) o, si no se informa, el que S ya tenía.

A estas tres se suma la regla c: nuevaInicio ≥ 08:00 y nuevaFin ≤ 17:00.

**Por qué hacen falta Llegada y Salida.** La Llegada solo mira hacia atrás. Si la nueva visita se coloca **antes** de una que ya existe, no tiene visita anterior y la Llegada la aceptaría aunque luego no diera tiempo a llegar a la existente. La Salida cubre ese caso. Basta con comparar con la anterior y la siguiente inmediatas: como no puede haber solapes, cualquier otra visita está todavía más lejos.

### Algoritmo al programar

1. Validar los campos, incluidos los desplazamientos: vacío o menor de 30 se rechaza con "El desplazamiento mínimo es 30 min". Si hay errores de campos, se devuelven sin seguir.
2. Cargar las visitas activas (Programadas y Completadas) del técnico en esa fecha, ordenadas por hora.
3. Comprobar la jornada y el **Solape** contra todas.
4. Buscar la visita anterior (P) y la siguiente (S). El origen es P o, si no existe, la Sede. El servidor lo calcula siempre; lo que diga el navegador se ignora.
5. Comprobar la **Llegada** y la **Salida**.
6. Sin errores: insertar la nueva y, en la misma transacción, actualizar S para que su origen sea la visita nueva y su desplazamiento el indicado.

### Tabla de reglas

| Código | Regla | Condición que la incumple | Mensaje de ejemplo |
| --- | --- | --- | --- |
| `CAMPOS` | Datos válidos | Falta un campo, formato incorrecto, duración fuera de 15–540, desplazamiento vacío, por debajo del mínimo (30 entre obras diferentes; 0 desde la sede o en la misma obra) o mayor de 240, técnico u obra inexistentes | "El desplazamiento mínimo es 30 min" |
| `PERMISO` | Rol | Un técnico intenta programar, o actuar sobre una visita de otro técnico | "No tienes permiso para modificar esta visita" |
| `JORNADA` | Regla c | Empieza antes de las 08:00, acaba después de las 17:00, o sale de la sede antes de las 08:00 | "Con 30 min desde la sede, la visita no puede empezar antes de las 08:30" |
| `SOLAPE` | Regla a | Falla la comprobación 1 | "Solapa con la visita de 10:00 a 11:00 en Obra A" |
| `DESPLAZAMIENTO` | Regla b | Falla la Llegada o la Salida | "No da tiempo a llegar desde Obra A (acaba 11:00): con 30 min de desplazamiento, como pronto a las 11:30" / "No da tiempo a llegar a la siguiente visita en Obra A a las 10:00: esta visita debería acabar como tarde a las 09:30" |
| `ESTADO` | Transiciones | Completar o cancelar una visita que no está Programada | "La visita ya está Completada" |
| `MOMENTO` | Completar | Completar antes de la hora de inicio de la visita | "Podrás completar esta visita a partir del 30/10 a las 10:00" |
| `TEXTO` | Cierre | Comentario de menos de 10 caracteres o motivo de menos de 5 | "Describe el trabajo realizado (mínimo 10 caracteres)" |

Se devuelven **todos** los errores a la vez. Si una visita solapa con la anterior o la siguiente, no se informa además de desplazamiento con esa misma visita.

### Ejemplos

Ana tiene una visita en la Obra A de 10:00 a 11:00, con 30 min de desplazamiento. Se intenta programar otra suya; salvo que se indique, el desplazamiento es 30.

| # | Nueva visita | Comprobación decisiva | Resultado |
| --- | --- | --- | --- |
| 1 | Obra B, 11:15–12:15 | Llegada: 11:15 ≥ 11:00 + 30 = 11:30 ✘ | `DESPLAZAMIENTO`: faltan 15 min |
| 2 | Obra B, 11:30–12:30 | Llegada: 11:30 ≥ 11:30 ✔ | Se guarda |
| 3 | Obra B, 10:30–11:30 | Solape: 11:30 ≤ 10:00 ✘ y 10:30 ≥ 11:00 ✘ | `SOLAPE` |
| 4 | Obra B, 08:30–09:00 | Llegada desde sede: 08:30 − 30 = 08:00 ✔. Salida: 10:00 ≥ 09:00 + 30 ✔ | Se guarda |
| 5 | Obra B, 09:30–09:50 | Llegada desde sede ✔. Salida: 10:00 ≥ 09:50 + 30 = 10:20 ✘ | `DESPLAZAMIENTO`: no da tiempo a llegar a la Obra A |
| 6 | Obra B, 11:30–12:30, desplazamiento 45 | Llegada: 11:30 ≥ 11:45 ✘ | `DESPLAZAMIENTO`: faltan 15 min |
| 7 | Obra B, 11:30–12:30, desplazamiento 20 | Validación de campos: 20 < 30 ✘ | CAMPOS: "El desplazamiento mínimo es 30 min"; se corrige en el formulario |
| 8 | Obra B, 08:00–08:45, primera del día | Desde la sede no hay mínimo (R2): con 0 min sale a las 08:00 ✔. Si se indican 30 min: 08:00 − 30 = 07:30 ✘ | Se guarda con 0 min; `JORNADA` si se indican 30 |
| 9 | Obra A, 11:00–12:00 (misma obra) | En la misma obra no hay mínimo (R1): con 0 min, 11:00 ≥ 11:00 ✔ | Se guarda |

El ejemplo 5 es el caso que justifica la Salida: la nueva visita no tiene ninguna anterior, así que la Llegada la acepta, pero después no da tiempo a llegar a la Obra A.

### Qué pasa al cancelar o completar

- **Completar** no cambia ningún horario: la visita sigue ocupando su hueco.
- **Cancelar** libera el hueco. Si la visita siguiente (S) tenía como origen la cancelada, su origen pasa a ser la visita anterior a la cancelada, o la Sede. Su desplazamiento se mantiene, pero se marca **"Revisar desplazamiento"** y la agenda lo muestra con un aviso. La cancelación nunca se bloquea por esto, porque un técnico tiene que poder cancelar siempre.

### Concurrencia y defensa en la base de datos

El guardado, el completado y la cancelación se hacen en una transacción que primero bloquea la fila del técnico (`SELECT ... FROM tecnicos WHERE id = ? FOR UPDATE`), luego lee sus visitas del día, valida y escribe. Así dos planificadores no pueden guardar a la vez dos visitas incompatibles. Funciona igual en PostgreSQL y en MySQL (InnoDB).

La tabla tiene además `CHECK` para duración, desplazamiento, estado, jornada y coherencia de cierre (una Completada debe tener comentario y una Cancelada, motivo). No sustituyen al validador, pero impiden datos imposibles si alguien inserta a mano.

## 5. Modelo de datos

Cinco tablas: `sede`, `tecnicos`, `obras`, `usuarios` y `visitas`. La visita guarda duración y desplazamiento (no horas de fin ni de salida, que se calculan) y una referencia a la visita de la que sale el técnico, para poder recalcular cuando se intercala o se cancela.

| Tabla | Campo | Tipo (PostgreSQL) | Notas |
| --- | --- | --- | --- |
| sede | id, nombre, direccion, municipio | SERIAL PK, VARCHAR | Un único registro |
| tecnicos | id, nombre, activo | SERIAL PK, VARCHAR(100), BOOLEAN | Solo los activos salen en el formulario |
| obras | id, nombre, direccion, municipio | SERIAL PK, VARCHAR |  |
| usuarios | id | SERIAL PK |  |
| usuarios | email | VARCHAR(150) UNIQUE | Login |
| usuarios | password\_hash | VARCHAR(255) | `password_hash()` de PHP (bcrypt) |
| usuarios | rol | VARCHAR(12) | PLANIFICADOR o TECNICO |
| usuarios | tecnico\_id | INT FK → tecnicos, NULL | Obligatorio si el rol es TECNICO |
| visitas | id, tecnico\_id, obra\_id, fecha, hora\_inicio | Igual que antes |  |
| visitas | duracion\_min | INT | 15–540 |
| visitas | desplazamiento\_min | INT DEFAULT 30 | 0–240; el validador exige 30 entre obras diferentes. Minutos para llegar a esta visita |
| visitas | origen\_tipo | VARCHAR(6) | SEDE o VISITA |
| visitas | origen\_visita\_id | INT FK → visitas, NULL | Visita desde la que sale; NULL si sale de la sede |
| visitas | revisar\_desplazamiento | BOOLEAN DEFAULT FALSE | Se activa si el origen cambia por una cancelación |
| visitas | estado | VARCHAR(12) | Programada, Completada o Cancelada |
| visitas | comentario\_cierre | TEXT NULL | Obligatorio en Completada |
| visitas | motivo\_cancelacion | TEXT NULL | Obligatorio en Cancelada |
| visitas | creada\_por / completada\_por / cancelada\_por | INT FK → usuarios | Trazabilidad |
| visitas | creada\_en / completada\_en / cancelada\_en | TIMESTAMP | Trazabilidad |

Migración `db/migrations/001_esquema.sql` (borrador, parte nueva y tabla de visitas):

```sql
CREATE TABLE sede (
  id        SERIAL PRIMARY KEY,
  nombre    VARCHAR(150) NOT NULL,
  direccion VARCHAR(200) NOT NULL,
  municipio VARCHAR(100) NOT NULL
);

CREATE TABLE usuarios (
  id            SERIAL PRIMARY KEY,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol           VARCHAR(12)  NOT NULL CHECK (rol IN ('PLANIFICADOR', 'TECNICO')),
  tecnico_id    INT NULL REFERENCES tecnicos(id),
  activo        BOOLEAN NOT NULL DEFAULT TRUE,
  CHECK ((rol = 'TECNICO') = (tecnico_id IS NOT NULL))
);

CREATE TABLE visitas (
  id                     SERIAL PRIMARY KEY,
  tecnico_id             INT  NOT NULL REFERENCES tecnicos(id),
  obra_id                INT  NOT NULL REFERENCES obras(id),
  fecha                  DATE NOT NULL,
  hora_inicio            TIME NOT NULL,
  duracion_min           INT  NOT NULL CHECK (duracion_min BETWEEN 15 AND 540),
  desplazamiento_min     INT  NOT NULL DEFAULT 30 CHECK (desplazamiento_min BETWEEN 0 AND 240),  -- 0: desde la sede o en la misma obra (R1 y R2)
  origen_tipo            VARCHAR(6) NOT NULL CHECK (origen_tipo IN ('SEDE', 'VISITA')),
  origen_visita_id       INT NULL REFERENCES visitas(id),
  revisar_desplazamiento BOOLEAN NOT NULL DEFAULT FALSE,
  estado                 VARCHAR(12) NOT NULL DEFAULT 'Programada'
                         CHECK (estado IN ('Programada', 'Completada', 'Cancelada')),
  comentario_cierre      TEXT NULL,
  motivo_cancelacion     TEXT NULL,
  creada_por             INT NULL REFERENCES usuarios(id),  -- NULL = carga inicial (seed)
  creada_en              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completada_por         INT NULL REFERENCES usuarios(id),
  completada_en          TIMESTAMP NULL,
  cancelada_por          INT NULL REFERENCES usuarios(id),
  cancelada_en           TIMESTAMP NULL,
  CHECK (hora_inicio >= TIME '08:00'),
  -- En minutos: TIME + INTERVAL da la vuelta a medianoche y 16:00 + 540 min = 01:00 pasaría
  CHECK (EXTRACT(EPOCH FROM hora_inicio) / 60 + duracion_min <= 1020),
  CHECK ((origen_tipo = 'SEDE') = (origen_visita_id IS NULL)),
  CHECK (estado <> 'Completada' OR comentario_cierre IS NOT NULL),
  CHECK (estado <> 'Cancelada'  OR motivo_cancelacion IS NOT NULL)
);

CREATE INDEX idx_visitas_tecnico_fecha ON visitas (tecnico_id, fecha);
CREATE INDEX idx_visitas_fecha_hora    ON visitas (fecha, hora_inicio);
```

La regla "salir de la sede no antes de las 08:00" **no** se pone como `CHECK`: cuando una cancelación convierte a la sede en el nuevo origen de otra visita, ese `CHECK` haría fallar la cancelación. Vive solo en el validador y en el aviso "Revisar desplazamiento".

**Nota para MySQL:** cambiar `SERIAL` por `INT AUTO_INCREMENT`, `REFERENCES` por `FOREIGN KEY (...) REFERENCES ...`, y el `CHECK` de fin de jornada por `TIME_TO_SEC(hora_inicio)/60 + duracion_min <= 1020`. Los `CHECK` se respetan desde MySQL 8.0.16.

**Datos de ejemplo.** `db/seed.sql` crea la sede, 4 técnicos, 6 obras en 4 municipios y unas 12 visitas relativas a `CURRENT_DATE` (una Cancelada con motivo, una Completada con comentario y casos límite de desplazamiento). Los **usuarios** no van en el SQL, para no subir contraseñas al repositorio: los crea `php bin/crear_usuarios_demo.php`, que lee `DEMO_PASSWORD` del `.env` y genera un planificador y un usuario por técnico (`ana@demo.local`, etc.).

## 6. Pantallas y API

Tres vistas en HTML y JS vanilla: Login, Planificación (planificador) y Mi agenda (técnico). Tras el login, `app.js` pide `GET /api/yo` y muestra la vista del rol; el servidor vuelve a comprobar el rol en cada petición, así que ocultar botones es solo comodidad.

### Vista Planificación

- **Formulario Nueva visita**: técnico, fecha, obra, hora de inicio (pasos de 15 min), duración, **desplazamiento** (precargado con 30, numérico con mínimo 30 y máximo 240; si el servidor lo rechaza, el mensaje aparece junto al campo para corregirlo) con el texto del origen calculado ("Desde la sede" / "Desde Obra X, acaba 11:00"). Si hay una visita posterior en otra obra aparece el campo **desplazamiento hasta la siguiente**.
- **Panel de agenda** junto al formulario, visible en cuanto hay técnico y fecha: franja de 08:00 a 17:00 con visitas (color por estado), desplazamientos (rayado), huecos libres (con sus minutos) y la visita propuesta en verde o rojo. Debajo, la lista textual de esas mismas visitas, para leerla sin depender del color.
- **Previsualización con la misma lógica del servidor**: al cambiar cualquier campo, `app.js` llama (con 400 ms de espera) a `POST /api/visitas/validar`, que ejecuta el validador sin guardar. Así las reglas solo existen una vez, en PHP, y el panel nunca dice algo distinto de lo que dirá Guardar.
- **Filtros y listado**: técnico, fecha y estado. Columnas: Fecha, Hora, Técnico, Obra, Desplazamiento, Estado, Acción. Las Completadas y Canceladas muestran comentario o motivo al pulsar el icono; las que tienen "Revisar desplazamiento" llevan un aviso amarillo.

### Vista Mi agenda (técnico)

- Día actual por defecto, con flechas de día anterior y siguiente y la misma franja horaria.
- Tarjetas por visita: hora, obra, dirección (enlace a mapa), desplazamiento y origen, estado.
- Botón **Completar** (solo si es Programada y ya ha llegado su hora) y botón **Cancelar** (si es Programada). Ambos abren una ventana con un campo de texto obligatorio y contador de caracteres. Diseño pensado para móvil.

### API JSON

| Método | Ruta | Rol | Uso | Respuestas |
| --- | --- | --- | --- | --- |
| POST | `/api/login` | Público | Inicia sesión | 200; 401 credenciales incorrectas |
| POST | `/api/logout` | Ambos | Cierra sesión | 204 |
| GET | `/api/yo` | Ambos | Usuario, rol y técnico | 200; 401 |
| GET | `/api/tecnicos`, `/api/obras` | Planificador | Desplegables | 200; 403 |
| GET | `/api/visitas?tecnico_id=&fecha=&estado=` | Ambos | Listado; al técnico se le fuerza su `tecnico_id` | 200; 422 |
| GET | `/api/agenda?tecnico_id=&fecha=` | Ambos | Visitas del día, huecos libres, avisos; técnico solo la suya | 200; 403 |
| POST | `/api/visitas/validar` | Planificador | Valida sin guardar; devuelve origen calculado y errores | 200 |
| POST | `/api/visitas` | Planificador | Programar | 201; 422; 403 |
| POST | `/api/visitas/{id}/completar` | Técnico (propio) | Completar con `comentario` | 200; 403; 409; 422 |
| POST | `/api/visitas/{id}/cancelar` | Ambos (técnico solo propias) | Cancelar con `motivo` | 200; 403; 404; 409; 422 |

Respuesta de `GET /api/agenda` (resumida):

```json
{
  "tecnico": { "id": 1, "nombre": "Ana Puig" },
  "fecha": "2026-11-03",
  "jornada": { "inicio": "08:00", "fin": "17:00" },
  "visitas": [
    { "id": 7, "obra": "Can Ribes", "inicio": "09:00", "fin": "10:30",
      "desplazamiento_min": 40, "origen": "Sede", "estado": "Programada",
      "revisar_desplazamiento": false }
  ],
  "huecos": [ { "inicio": "08:00", "fin": "08:20", "minutos": 20 },
              { "inicio": "10:30", "fin": "17:00", "minutos": 390 } ]
}
```

Un hueco es el tiempo no ocupado por ninguna visita activa ni por el desplazamiento que la precede (09:00 − 40 min = 08:20). Los errores siguen el formato `{"errores": [{"regla", "mensaje"}]}` en todos los endpoints.

**Seguridad.** Contraseñas con `password_hash` / `password_verify`; sesión PHP con cookie `HttpOnly`, `SameSite=Strict` y `Secure` en todos los entornos; caducidad por inactividad y destrucción de la sesión al salir; `session_regenerate_id()` al entrar; token CSRF en cabecera para todas las peticiones POST salvo el login (ver 11.4); sentencias preparadas de PDO; salida con `textContent`, nunca `innerHTML` con datos.

## 7. Arquitectura y estructura del repositorio

Se mantienen las tres capas pequeñas (router, servicios, repositorios) y se añade una capa de autenticación. Cada archivo hace una cosa: una regla nueva va al validador, un permiso nuevo a `Permisos.php`, una consulta nueva a un repositorio.

```
planificador-visitas/
├── public/                    # Única carpeta expuesta por el servidor web
│   ├── index.php              # Router: API + HTML
│   ├── index.html             # Contenedor de las tres vistas
│   ├── css/estilos.css
│   └── js/
│       ├── api.js             # fetch con token CSRF y manejo de errores
│       ├── planificacion.js   # Formulario, previsualización, listado
│       ├── agenda.js          # Dibuja la franja 08:00–17:00 (compartido)
│       └── mi-agenda.js       # Vista del técnico, completar y cancelar
├── src/
│   ├── Database.php           # Conexión PDO desde DATABASE_URL
│   ├── Horas.php              # "10:30" <-> 630 minutos
│   ├── Auth.php               # Login, sesión, CSRF, usuario actual
│   ├── Permisos.php           # Qué puede hacer cada rol sobre cada visita
│   ├── ValidadorVisita.php    # Reglas a, b, c y desplazamiento (puro, sin BD)
│   ├── CalculadoraAgenda.php  # Huecos libres del día (puro, sin BD)
│   ├── VisitaService.php      # Transacciones: programar, completar, cancelar
│   ├── VisitaRepository.php
│   └── UsuarioRepository.php
├── bin/crear_usuarios_demo.php
├── db/
│   ├── migrations/001_esquema.sql
│   └── seed.sql
├── tests/
│   ├── ValidadorVisitaTest.php
│   ├── CalculadoraAgendaTest.php
│   └── PermisosTest.php
├── docker-compose.yml         # php:8.3-apache + postgres:16
├── Dockerfile                 # Para Render
├── composer.json              # PHPUnit (dev) y autoload
├── .env.example               # DATABASE_URL, DEMO_PASSWORD, APP_ENV
├── .gitignore                 # .env, vendor/
├── README.md
└── DECISIONES.md
```

**Flujo de "Guardar visita":**

1. `planificacion.js` envía el formulario como JSON a `POST /api/visitas` con el token CSRF.
2. `index.php` comprueba sesión y CSRF y llama a `VisitaService::programar()`.
3. `Permisos` verifica que el usuario es planificador.
4. El servicio abre transacción y bloquea la fila del técnico con `FOR UPDATE`.
5. El repositorio lee las visitas activas del técnico en esa fecha.
6. `ValidadorVisita` calcula origen, visita anterior y siguiente, y devuelve los errores.
7. Sin errores: INSERT de la nueva, UPDATE del origen y desplazamiento de la siguiente si la hay, COMMIT y 201. Con errores: ROLLBACK y 422.

**Flujo de "Cancelar":** sesión y CSRF → `Permisos` (planificador, o técnico dueño) → transacción con bloqueo → comprobar estado Programada y motivo → UPDATE a Cancelada → si una visita tenía como origen la cancelada, reasignar su origen y marcar "Revisar desplazamiento" → COMMIT.

**Configuración.** Variables `DATABASE_URL`, `DEMO_PASSWORD` y `APP_ENV` (solo decide el nivel de detalle de los logs internos; al cliente nunca llegan detalles técnicos en ningún entorno). En local las define el `.env` a partir de `.env.example`; en Render, el panel de variables de entorno. Nunca en el código. Todas las decisiones de seguridad siguen la sección 11.

**Arranque local (objetivo README):** clonar, `cp .env.example .env` (poner una `DEMO_PASSWORD`), `docker compose up`. Postgres ejecuta `001_esquema.sql` y `seed.sql` al crearse, y el contenedor PHP ejecuta `bin/crear_usuarios_demo.php` al arrancar si no hay usuarios. Abrir `http://localhost:8080` y entrar como `planificador@demo.local` o `ana@demo.local`.

## 8. Casos de prueba

Se automatizan en PHPUnit contra `ValidadorVisita`, `CalculadoraAgenda` y `Permisos`, que no necesitan base de datos. Partida común: Ana tiene una visita Programada de 10:00 a 11:00 en la Obra A, con 30 min de desplazamiento desde la sede.

### Horario básico (enunciado)

| # | Nueva visita de Ana | Resultado esperado | Qué comprueba |
| --- | --- | --- | --- |
| 1 | 12:00–13:00, Obra B, despl. 30 | Se guarda | Caso normal |
| 2 | 10:30–11:30, Obra A | `SOLAPE` | Solape parcial |
| 3 | 09:00–12:00, Obra B | `SOLAPE` (no además `DESPLAZAMIENTO`) | Un solo error por conflicto |
| 4 | 11:00–12:00, Obra A, despl. 0 | Se guarda (R1) | En la misma obra no se exige desplazamiento (enunciado, regla b) |
| 5 | 11:00–12:00, Obra B | `DESPLAZAMIENTO` | Pegadas en obras distintas |
| 6 | 11:30–12:00, Obra B, despl. 30 | Se guarda | Exactamente 30 min |
| 7 | 16:00–17:00, Obra B | Se guarda | Acaba justo a las 17:00 |
| 8 | 16:30–17:15, Obra B | `JORNADA` | Acaba después de las 17:00 |
| 9 | 10:30–17:30, Obra B | `SOLAPE` + `JORNADA` | Devuelve todos los errores |
| 10 | 10:00–11:00, Obra A, tras cancelar la existente | Se guarda | Cancelar libera el horario |
| 11 | Mismo horario, otro técnico | Se guarda | Las reglas son por técnico |
| 12 | Duración 0, técnico inexistente, fecha "31/02" | `CAMPOS` | Validación de entrada |

### Desplazamiento (ampliación A1)

| # | Caso | Resultado esperado | Qué comprueba |
| --- | --- | --- | --- |
| 13 | 11:30–12:30, Obra B, despl. 45 | `DESPLAZAMIENTO` (faltan 15 min) | El valor introducido manda si es mayor que 30 |
| 14 | 11:30–12:30, Obra B, despl. 20 | CAMPOS: "El desplazamiento mínimo es 30 min" | Premisa 11.1: se rechaza, no se corrige en servidor |
| 15 | Primera visita del día 08:15, despl. 30 desde la sede | `JORNADA` | Salida de la sede antes de las 08:00 |
| 16 | Primera visita del día 08:30, despl. 30 desde la sede | Se guarda, origen Sede | Límite exacto |
| 17 | 08:30–09:15, Obra B, con "hasta la siguiente" = 50 | `DESPLAZAMIENTO` (09:15 + 50 > 10:00) | Comprobación hacia la visita siguiente |
| 18 | 08:30–09:00, Obra B, "hasta la siguiente" = 50 | Se guarda; la visita de las 10:00 pasa a origen Obra B y despl. 50 | Actualización de la siguiente |
| 19 | Cancelar la de 08:30 del caso 18 | Se cancela; la de las 10:00 vuelve a origen Sede y queda "Revisar desplazamiento" | Recalculo al cancelar sin bloquear |
| 20 | Origen enviado por el navegador = Sede, pero hay visita anterior | El servidor usa la visita anterior | El origen no se fía del cliente |

### Perfiles y estados (ampliación A2)

| # | Caso | Resultado esperado |
| --- | --- | --- |
| 21 | Ana completa su visita de las 10:00 a las 10:15 con comentario de 30 caracteres | Completada, guarda autor y hora |
| 22 | Ana completa una visita de mañana | `MOMENTO` |
| 23 | Ana completa con comentario de 4 caracteres | `TEXTO` |
| 24 | Ana cancela sin motivo | `TEXTO` |
| 25 | Ana cancela o completa una visita de Bernat | 403 `PERMISO` |
| 26 | Ana pide `GET /api/visitas?tecnico_id=2` | Recibe solo las suyas |
| 27 | Ana llama a `POST /api/visitas` | 403 `PERMISO` |
| 28 | Completar una visita Cancelada, o cancelar una Completada | 409 `ESTADO` |
| 29 | Nueva visita que solapa con una **Completada** | `SOLAPE` (las completadas siguen ocupando) |
| 30 | POST sin token CSRF | 403 |

Además, una prueba manual con `curl` documentada en el README: enviar directamente a la API una visita inválida y una petición de técnico sobre una visita ajena, y comprobar que el servidor devuelve 422 y 403. Es la forma más clara de demostrar que reglas y permisos no dependen del navegador.

## 9. Plan de trabajo

Con las ampliaciones el trabajo sube de unas 3 horas a unas **6 horas**. El orden sigue garantizando que, si se corta en cualquier punto, lo hecho funcione: el esquema y el validador incluyen el desplazamiento desde el principio (cuesta poco y evita rehacerlos), y los perfiles y la agenda van después del núcleo.

| Fase | Tarea | Tiempo | Acumulado | Entregable que deja |
| --- | --- | --- | --- | --- |
| 0 | Repo, `.gitignore`, `.env.example`, `docker-compose.yml`, `composer.json` | 15 min | 0:15 | Entorno arrancando vacío |
| 1 | Esquema completo, `seed.sql`, `bin/crear_usuarios_demo.php` | 30 min | 0:45 | BD con datos y usuarios demo |
| 2 | `Horas` + `ValidadorVisita` con desplazamiento y origen + tests 1–20 | 60 min | 1:45 | Reglas verificadas en verde |
| 3 | Repositorios y `VisitaService`: programar con actualización de la siguiente, cancelar con recálculo | 35 min | 2:20 | Lógica completa en servidor |
| 4 | Router y endpoints de visitas; prueba con `curl` | 25 min | 2:45 | API usable sin interfaz |
| 5 | Vista Planificación: formulario con desplazamiento, filtros, listado, cancelar con motivo | 40 min | 3:25 | **Corte mínimo: cumple el enunciado + A1** |
| 6 | `Auth`, `Permisos`, login, CSRF, completar + tests 21–30 | 45 min | 4:10 | Perfiles seguros en servidor |
| 7 | Vista Mi agenda del técnico con completar y cancelar | 35 min | 4:45 | A2 completo |
| 8 | `CalculadoraAgenda`, `GET /api/agenda`, `POST /api/visitas/validar`, `agenda.js` | 45 min | 5:30 | A3 completo, con previsualización |
| 9 | README probado desde un clon limpio y `DECISIONES.md` | 20 min | 5:50 | Entrega documentada |
| 10 | Publicar: Neon + Render (variables `DATABASE_URL`, `DEMO_PASSWORD`, `APP_ENV`) | 20 min | 6:10 | URL pública |

**Si solo hay 3 horas** (lo que pide la prueba): entregar hasta la fase 5 más README y `DECISIONES.md`, y documentar las fases 6–8 como pendientes con este mismo diseño. Lo entregado cumple el enunciado entero y ya incluye el desplazamiento variable.

**Puntos de control.** Fase 2: tests en verde. Fase 4: la API rechaza por `curl` una visita inválida. Fase 6: un técnico recibe 403 sobre una visita ajena. Fase 9: se repasa la tabla 11.11 punto por punto y se clona el repo en una carpeta nueva y se cronometra el arranque siguiendo solo el README. Un commit por fase.

**Uso de IA previsto.** Generar el esqueleto de archivos, el CSS, el seed y la franja de agenda; proponer casos de prueba. Revisar a mano siempre el validador, los permisos y el SQL. Durante este análisis ya aparecieron tres correcciones útiles para `DECISIONES.md`:

- El primer `CHECK` de jornada sumaba `hora_inicio + INTERVAL`, que en PostgreSQL da la vuelta a medianoche (16:00 + 540 min = 01:00) y habría aceptado visitas imposibles. Se corrigió calculando en minutos.
- Se propuso un `CHECK` para "salir de la sede no antes de las 08:00", pero habría hecho fallar cancelaciones legítimas al cambiar el origen de la visita siguiente. Se dejó solo en el validador.
- `creada_por` era obligatorio, pero el seed se carga antes de que existan usuarios. Se permitió NULL para la carga inicial.

## 10. Supuestos, riesgos y pendientes

### Supuestos (irán tal cual a `DECISIONES.md`)

| Duda | Supuesto | Motivo |
| --- | --- | --- |
| ¿Pueden tocarse dos visitas? | Sí: intervalos `[inicio, fin)` | Interpretación habitual en agendas |
| ¿30 min en la misma obra? | No: en la misma obra el mínimo es 0 (R1, resuelto el 02/11/2026) | El enunciado solo exige 30 min entre obras diferentes |
| 30 min frente a desplazamiento introducido | Por defecto 30; un valor menor se rechaza y se corrige en el formulario | Premisa de seguridad 11.1; garantiza el mínimo del enunciado |
| Desplazamiento desde la sede | Sin mínimo (R2, resuelto el 02/11/2026): la primera visita puede empezar a las 08:00. Si se indica un tiempo desde la sede, la salida no puede ser antes de las 08:00 | El enunciado solo exige empezar y acabar dentro de la jornada; el técnico empieza su jornada en la sede |
| Vuelta a la sede al final | No se valida | No se ha pedido; queda pendiente |
| Origen del desplazamiento | Lo calcula el servidor, no se elige a mano | Evita incoherencias con la agenda real |
| Visita intercalada | Se pide el desplazamiento hasta la siguiente y se actualiza esa visita | Mantiene la cadena de orígenes correcta |
| Cancelación que cambia orígenes | No se bloquea; se marca "Revisar desplazamiento" | Un técnico debe poder cancelar siempre |
| Completar | Solo el técnico de la visita, a partir de su hora de inicio | Una visita no puede darse por hecha antes de empezar |
| Completadas | Siguen ocupando su horario | Ocurrieron de verdad |
| Cancelar | Planificador o técnico dueño, motivo obligatorio para ambos | Trazabilidad uniforme |
| Estados finales | Completada y Cancelada no se reactivan ni editan | Historial limpio; reprogramar = visita nueva |
| Duración | 15–540 min; desplazamiento 0–240 min, con mínimo 30 entre obras diferentes | Jornada completa (desde las 08:00, 540 min caben justos) y un máximo razonable |
| Fechas pasadas y fines de semana | Se permiten al programar | El enunciado no los restringe |
| Errores | Se devuelven todos a la vez | Se corrige en un solo intento |
| Datos maestros | Técnicos, obras, sede y usuarios solo por script | El foco son las reglas y los perfiles |

### A revisar antes de la versión final

**Resueltas el 02/11/2026, antes de la entrega, siguiendo el enunciado** (se aplica la alternativa de cada una; aprobado por el responsable del proyecto). Eran dos consecuencias del desplazamiento mínimo de 30 que había que confirmar con negocio. El antiguo R3 (ajuste automático a 30) queda resuelto: un valor menor se rechaza, según la premisa 11.1. Cambiar cualquiera de ellas es pequeño: afecta a la validación del desplazamiento en `ValidadorVisita` y a unos pocos tests.

| Id | Punto | Comportamiento en esta versión | Por qué revisarlo | Alternativa | Afecta a |
| --- | --- | --- | --- | --- | --- |
| R1 | Visitas seguidas en la **misma obra** | **Resuelto:** mínimo 0 en la misma obra (antes exigían 30 min) | El enunciado solo exige los 30 min entre obras diferentes. Esta versión es más estricta y rechaza casos que el enunciado permite; un evaluador puede probarlo | Si la visita anterior o la siguiente es en la misma obra, desplazamiento 0 | Sección 4 ejemplo 9; test 4 |
| R2 | Primera visita del día, **desde la sede** | **Resuelto:** sin mínimo desde la sede; se puede empezar a las 08:00 (antes, nada antes de las 08:30) | Una obra cercana a la sede podría necesitar menos tiempo y perder media hora de jornada cada día. Además, con este mínimo una visita de 540 min (la duración máxima) nunca cabe: el máximo real es 510 | Permitir desde la sede un valor menor (0–29) sin ajuste, o un tiempo fijo por obra | Sección 4 ejemplo 8; tests 15 y 16; duración máxima útil |

### La agenda ya no es opcional

La funcionalidad opcional propuesta en la primera versión (agenda diaria del técnico) pasa a ser la ampliación A3, descrita en los CU3 y CU6 y en la sección 6. Se le añade la previsualización contra el servidor, que avisa del conflicto antes de pulsar Guardar.

### Riesgos

- **Tiempo**: las ampliaciones doblan el esfuerzo de la prueba. Mitigado con el corte mínimo de la sección 9.
- **Complejidad del recálculo de orígenes** al intercalar y cancelar: es la parte más fácil de romper. Mitigado con los tests 17–20 y con la transacción con bloqueo.
- **Seguridad del login**: una aplicación pública con usuarios demo. Contraseña demo solo por variable de entorno y cuentas demo sin datos reales.
- **Arranque en frío de Render y Neon** en planes gratuitos (30–60 s la primera vez). Se avisa en el README.
- **PHP frente al stack valorado**. Justificado en `DECISIONES.md` por mantenibilidad.

### Pendiente para siguientes versiones

- Gestión desde pantalla de técnicos, obras, sede y usuarios (incluido cambio de contraseña).
- Editar o reprogramar una visita.
- Validar la vuelta a la sede al final de la jornada.
- Sugerir el desplazamiento a partir de una tabla de tiempos entre obras o de una API de mapas, en lugar de introducirlo a mano.
- Festivos, vacaciones y jornadas distintas por técnico.
- Notificar al planificador cuando un técnico cancela (email o aviso en pantalla).
- Paginación del listado y exportación a CSV.
- Límite de intentos de login contra fuerza bruta. Propuesta estudiada: 5 fallos seguidos en 15 min bloquean ese email 15 min (también si el email no existe, para no revelar qué cuentas hay); contador en una tabla nueva `intentos_login` con `INSERT ... ON CONFLICT DO UPDATE`; respuesta 429 con un código de regla nuevo. Riesgo a valorar: cualquiera que conozca un email puede bloquear esa cuenta 15 min.

## 11. Premisas obligatorias de seguridad para el desarrollo

Las siguientes premisas deberán aplicarse a todo el código generado o modificado en los desarrollos actuales y futuros. Prevalecen sobre cualquier otra indicación de este documento.

### 11.1 Validación de entradas

- Toda entrada externa se considerará **no confiable**.
- Validar **siempre en servidor**, independientemente de las validaciones realizadas en el frontend.
- Validar tipo, formato, longitud, rango y valores permitidos mediante **listas blancas** cuando sea posible.
- Toda entrada que no cumpla las condiciones establecidas deberá ser rechazada.

### 11.2 Acceso a base de datos

- Utilizar **exclusivamente consultas parametrizadas / prepared statements**.
- Queda prohibida la concatenación de variables o datos externos para construir sentencias SQL.
- La cuenta utilizada por la aplicación para acceder a la base de datos deberá disponer únicamente de los permisos necesarios.

### 11.3 Prevención de XSS

- Todo dato mostrado al usuario deberá codificarse o escaparse según el contexto de salida.
- No insertar directamente datos externos mediante `innerHTML` u otros mecanismos equivalentes.
- No utilizar `eval()` para procesar datos externos.

### 11.4 Protección CSRF

- Toda petición que cree, modifique o elimine información deberá estar protegida mediante **token anti-CSRF validado en servidor**.
- Las peticiones `GET` no deberán realizar cambios de estado.
- Los tokens deberán ser aleatorios, criptográficamente seguros y estar vinculados a la sesión.

**Excepción aprobada en este proyecto (30/10/2026): el login no exige token.** Antes de entrar no hay sesión ni token, y exigirlo obligaba a una petición previa solo para obtenerlo. El login no crea ni modifica datos de negocio: el riesgo que queda es el *login CSRF* (que una web ajena haga entrar al navegador de la víctima con la cuenta del atacante). Se compensa así:

- El login solo acepta `Content-Type: application/json`. Una web ajena no puede enviar ese tipo a otro dominio sin una comprobación CORS previa, y el servidor no habilita CORS.
- Si la petición trae cabecera `Origin` de otro sitio, se rechaza con 403.
- La cookie de sesión es `SameSite=Strict` y el id de sesión se regenera al entrar.
- El token se genera al iniciar sesión, se devuelve en la respuesta del login y se exige en todos los demás POST.

### 11.5 Cookies y sesiones

- Las cookies de autenticación y sesión deberán configurarse con `Secure`, `HttpOnly` y `SameSite=Strict`.
- No almacenar información sensible directamente en cookies.
- Regenerar el identificador de sesión después de la autenticación.
- Establecer expiración de sesión y destruir correctamente la sesión al cerrar sesión.
- `SameSite=Strict` será el valor por defecto salvo necesidad funcional justificada.

### 11.6 Autenticación y autorización

- Toda autenticación y autorización deberá realizarse **en servidor**.
- Nunca confiar en controles realizados exclusivamente desde el frontend.
- Cada endpoint u operación deberá comprobar que el usuario está autenticado y autorizado para realizarla.
- Aplicar el principio de **mínimo privilegio**.

### 11.7 Contraseñas, tokens y secretos

- Las contraseñas nunca se almacenarán en texto plano.
- Utilizar funciones específicas y seguras de hash de contraseñas.
- Queda prohibido incluir contraseñas, API keys, tokens o secretos directamente en el código fuente.
- Los secretos deberán obtenerse desde configuración segura o variables de entorno.

### 11.8 SSRF y conexiones externas

- Nunca realizar peticiones desde el servidor directamente a una URL recibida del usuario.
- Los destinos externos deberán validarse contra una **allowlist** previamente definida.
- Restringir protocolos y destinos permitidos.
- Evitar el acceso desde la aplicación a recursos internos que no sean necesarios.

### 11.9 Archivos y rutas

- Nunca construir rutas del sistema directamente con valores recibidos del usuario.
- Impedir `../` y cualquier otra forma de **Path Traversal**.
- En la subida de archivos, validar tamaño, extensión y tipo MIME.
- Los archivos subidos no deberán almacenarse de forma que puedan ejecutarse.

### 11.10 Errores y respuestas

- Nunca devolver al cliente stack traces, consultas SQL, rutas internas, credenciales o detalles de implementación.
- Los detalles técnicos deberán registrarse únicamente en logs internos.
- Los mensajes mostrados al usuario deberán ser genéricos y controlados.

### 11.11 Aplicación en este proyecto

| Premisa | Cómo se aplica | Cómo se verifica |
| --- | --- | --- |
| 11.1 Entradas | `ValidadorVisita` y una clase `Entrada` comprueban en servidor: ids enteros con `filter_var`, fecha `Y-m-d` estricta con `DateTime::createFromFormat`, hora `HH:MM` por expresión regular, duración 15–540, desplazamiento 0–240 con mínimo 30 entre obras diferentes (por debajo, "El desplazamiento mínimo es 30 min"), comentario 10–2.000 caracteres, motivo 5–2.000, estado y rol por lista blanca. Cualquier campo no esperado en el JSON se rechaza | Tests de `CAMPOS` y prueba con `curl` saltándose el formulario |
| 11.2 Base de datos | Solo PDO con sentencias preparadas y `PDO::ATTR_EMULATE_PREPARES = false`. El usuario de la aplicación solo tiene `SELECT`, `INSERT` y `UPDATE` sobre las tablas; sin `DELETE` (nada se borra) ni permisos de esquema. Las migraciones se ejecutan con otro usuario | Revisión: búsqueda de concatenaciones en SQL (`grep`); `GRANT` documentado en `001_esquema.sql` |
| 11.3 XSS | Todo dato se pinta con `textContent` y `createElement`; nunca `innerHTML` con datos ni `eval()`. Cabecera `Content-Security-Policy: default-src 'self'` como segunda barrera | Completar una visita con `<script>` en el comentario y comprobar que se ve como texto |
| 11.4 CSRF | Token `bin2hex(random_bytes(32))` generado al iniciar sesión, guardado en la sesión, enviado en la cabecera `X-CSRF-Token` y comparado con `hash_equals`. Aplica a todos los POST salvo el login (excepción de 11.4, compensada con `Content-Type: application/json` obligatorio, rechazo de `Origin` ajeno y `SameSite=Strict`). Ningún GET cambia datos | Test 30: POST sin token devuelve 403; login con `Origin` ajeno devuelve 403 |
| 11.5 Sesiones | `session_set_cookie_params` con `Secure`, `HttpOnly` y `SameSite=Strict` en todos los entornos (los navegadores aceptan `Secure` en `http://localhost`). `session.use_strict_mode=1`. En la cookie solo va el id de sesión. `session_regenerate_id(true)` al entrar. Caducidad por 30 min de inactividad y 8 h máximas. Al salir, `session_destroy()` y borrado de la cookie | Inspeccionar la cookie en el navegador; volver atrás tras cerrar sesión |
| 11.6 Autorización | El router tiene una tabla ruta → roles permitidos y deniega por defecto. `Permisos` comprueba además la propiedad de la visita. El técnico solo tiene acceso a lo suyo | Tests 25–27 |
| 11.7 Secretos | `password_hash()` con `PASSWORD_DEFAULT` y `password_verify()`. `DATABASE_URL` y `DEMO_PASSWORD` solo en variables de entorno; `.env` en `.gitignore` | Revisión del repositorio antes de publicarlo |
| 11.8 SSRF | La aplicación no hace ninguna petición saliente. Si en el futuro se integra una API de mapas, solo se llamará a un host fijo por `https`, configurado en servidor | No aplica en esta versión |
| 11.9 Archivos | No hay subida de archivos. El router no traduce rutas a ficheros; Apache solo sirve `public/` y el código de `src/` queda fuera de la raíz web | Pedir `/../src/Auth.php` y comprobar 404 |
| 11.10 Errores | `display_errors=Off`. Un manejador global registra el detalle con `error_log` y responde 500 con `{"errores":[{"regla":"INTERNO","mensaje":"Ha ocurrido un error. Inténtalo de nuevo."}]}`. El login responde siempre "Usuario o contraseña incorrectos", sin revelar si el email existe | Test unitario de `ManejadorErrores`: una excepción con rutas o SQL produce el 500 genérico sin esos detalles |
