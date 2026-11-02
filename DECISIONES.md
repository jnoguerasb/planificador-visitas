# Decisiones

## Qué se ha hecho y qué queda

**Hecho.** Lo que pide el enunciado (programar visitas sin solapes, con 30 min entre obras y dentro de la jornada de 08:00 a 17:00) y las tres ampliaciones:
- **A1. Desplazamiento variable:** el origen lo calcula el servidor y, al intercalar una visita, se actualiza la siguiente.
- **A2. Perfiles:** planificador y técnico; el técnico completa y cancela sus visitas.
- **A3. Agenda diaria:** con previsualización en verde o rojo, calculada con la misma lógica que el guardado.

La API y las dos interfaces están cubiertas por 400 tests (`composer verify`). El despliegue en Render y Neon está preparado (`docs/guia-despliegue.md`).

**Pendiente** (sección 10 del análisis):
- gestión de técnicos, obras y usuarios desde pantalla;
- editar o reprogramar visitas;
- vuelta a la sede al final del día;
- tiempos de desplazamiento sugeridos;
- festivos y jornadas por técnico;
- avisos al planificador;
- paginación y CSV;
- **límite de intentos de login** (propuesta estudiada: 5 fallos en 15 min bloquean el email 15 min).

## Decisiones principales

- **Stack.**
  - PHP 8.3 sin framework, PostgreSQL 16 y JavaScript vanilla sin build. Quien lo mantenga conoce PHP, JS y SQL, y no tiene que aprender un framework.
  - En desarrollo, Windows sin Docker (el equipo no permite virtualización); en producción, Docker en Render.
- **Reglas en un solo sitio.**
  - Clases puras sin base de datos: `ValidadorVisita`, `CalculadoraAgenda`, `Permisos`, `ValidadorCierre`.
  - La previsualización (`POST /api/visitas/validar`) usa exactamente la misma validación que Guardar. El navegador no decide nada: ni el origen, ni si la visita cabe, ni qué botones mostrar.
- **Concurrencia.** Cada escritura bloquea al técnico (`SELECT … FOR UPDATE`) antes de leer y validar. Un test con dos procesos lo comprueba.
- **Base de datos.**
  - Dos usuarios: la aplicación solo puede `SELECT`, `INSERT` y `UPDATE`; las migraciones las ejecuta el propietario.
  - Los `CHECK` con nombre son una segunda barrera, no sustituyen al validador.
- **Seguridad.**
  - Sesión con cookie `Secure`, `HttpOnly` y `SameSite=Strict`.
  - CSRF en todos los POST **salvo el login**. Es una excepción aprobada: se compensa con JSON obligatorio, rechazo de `Origin` ajeno y `SameSite=Strict`.
  - Cabecera `Content-Security-Policy: default-src 'self'` en todas las respuestas, y solo DOM seguro en el navegador.
  - Errores genéricos al cliente y detalle en el registro.
- **Entradas y respuestas.**
  - Si la petición no se puede leer, 400. Si se lee pero los datos no son válidos, 422. `PERMISO` da 403 y `ESTADO` da 409.
  - Los campos no esperados se rechazan, salvo el origen que pueda enviar el navegador, que se ignora (caso 20).
- **Hora.** Siempre Europe/Madrid (`Reloj` y cada conexión a PostgreSQL), aunque Render funcione en UTC.

## Supuestos

| Duda | Supuesto |
| --- | --- |
| ¿Pueden tocarse dos visitas? | Sí: intervalos `[inicio, fin)` |
| Visitas activas | Programadas y Completadas ocupan su horario; las Canceladas lo liberan |
| Desplazamiento | Hasta 240 min. Entre obras diferentes, al menos 30: un valor menor se **rechaza** ("El desplazamiento mínimo es 30 min"), nunca se corrige |
| **R1. Misma obra** | Sin mínimo: dos visitas seguidas en la misma obra pueden ir pegadas (regla b del enunciado: 30 min solo entre obras diferentes) |
| **R2. Salida desde la sede** | Sin mínimo: la primera visita puede empezar a las 08:00 (regla c). Si se indica un desplazamiento desde la sede, la salida no puede ser antes de las 08:00 |
| Cancelar | Nunca se bloquea. La siguiente cambia de origen y queda marcada "Revisar desplazamiento" |
| Completar | Solo el técnico de la visita, a partir de su hora de inicio. Comentario de 10 a 2.000 caracteres; motivo de cancelación de 5 a 2.000 |
| Estados finales | Completada y Cancelada no se reactivan ni se borran |

## Uso de la IA

El código lo ha escrito Claude Code siguiendo `CLAUDE.md`:
- por fases cerradas, con los tests escritos antes y comprobando que fallaban;
- con una puerta de validación por fase (`composer verify`) y la aprobación de la persona responsable;
- con algunos tests comprobados a la inversa: se quitaba a propósito la protección (el `FOR UPDATE`, el token CSRF, la regeneración del id de sesión) para ver que el test fallaba.

**Casos corregidos:**
1. **Análisis:**
   - el `CHECK` de fin de jornada con `TIME + INTERVAL` daba la vuelta a medianoche;
   - un `CHECK` de "salir de la sede" habría bloqueado cancelaciones;
   - `creada_por` obligatorio era incompatible con el seed.
2. **`crear_bases.ps1`:** una variable chocaba con un parámetro, porque PowerShell no distingue mayúsculas, y psql intentó entrar con el usuario de Windows. Lo detectó la persona al ejecutarlo. Se corrigió y se añadió una comprobación para que no vuelva a pasar.
3. **Dos `CHECK` que se solapaban:** un test de restricciones falló. Se corrigió el `CHECK`, no el test.
4. **Longitud del motivo:** la IA puso 2.000 caracteres sin ver que el análisis decía 500. Lo detectó en una revisión posterior y la persona decidió mantener 2.000 y corregir el análisis.
5. **R1 y R2:** al revisar el alcance contra el enunciado se vio que la aplicación era más estricta que él (no permitía empezar a las 08:00 ni encadenar visitas en la misma obra). Se ajustó antes de la entrega; también resolvió que una visita de 540 min no cupiera nunca.
