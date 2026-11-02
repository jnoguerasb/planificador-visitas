# Revisión de seguridad (Fase 8)

Repaso punto por punto de la tabla 11.11 del análisis contra el código entregado, con la prueba que lo demuestra.

**Leyenda:**
- ✔ cumplido y comprobado automáticamente;
- ✔* cumplido, con una comprobación manual pendiente;
- ⚠ cumplido con una excepción aprobada.

| Premisa | Estado | Cómo se aplica en el código | Prueba |
| --- | --- | --- | --- |
| **11.1 Entradas** | ✔ | `Entrada` lee el JSON de forma estricta: tipo de contenido, tamaño máximo de 16 KB, objeto JSON y campos no esperados rechazados. `ValidadorVisita` comprueba:<br>• ids enteros que existen;<br>• fecha `AAAA-MM-DD` real (`checkdate`);<br>• hora `HH:MM`;<br>• duración 15–540 y desplazamiento 0–240, con mínimo 30 entre obras diferentes (por debajo, "El desplazamiento mínimo es 30 min").<br>`ValidadorCierre` limita el comentario a 10–2.000 caracteres y el motivo a 5–2.000. Los estados y los roles van por lista blanca. El servidor nunca corrige un valor: lo rechaza | `EntradaTest`, `ValidadorVisitaTest` (más de 20 casos de `CAMPOS`), `ProgramarApiTest`, ejemplo de `curl` del README |
| **11.2 Base de datos** | ✔ | Solo sentencias preparadas, con `EMULATE_PREPARES=false` y `ERRMODE_EXCEPTION`. El usuario de la aplicación solo tiene `SELECT`, `INSERT` y `UPDATE`; migración y seed van con `planificador_admin`. Cada escritura va en una transacción con `FOR UPDATE` sobre el técnico | `PermisosBaseDatosTest` (DELETE, TRUNCATE, DROP, CREATE, ALTER → 42501), `ConcurrenciaTest`, regla de SQL de `check-security.sh` |
| **11.3 XSS** | ✔* | En el navegador solo `textContent`, `createElement` y `setAttribute`; ni `innerHTML` ni `eval`. Sin JavaScript ni estilos dentro del HTML. Cabecera `Content-Security-Policy: default-src 'self'` en todas las respuestas, también en los archivos estáticos en producción (Apache) | `check-security.sh`, `InterfazTest`, `probar_interfaz.ps1`. **Manual pendiente:** un comentario con `<script>` se ve como texto |
| **11.4 CSRF** | ⚠ | Token `bin2hex(random_bytes(32))` en la sesión, enviado en la cabecera `X-CSRF-Token` y comparado con `hash_equals` en todos los POST salvo el login.<br>**Excepción aprobada el 30/10/2026**, compensada así:<br>• el login solo acepta JSON (sin CORS);<br>• un `Origin` ajeno da 403;<br>• la cookie es `SameSite=Strict`.<br>Ningún GET cambia datos | `AutenticacionTest` (sin token, token alterado, token de otra sesión, `Origin` ajeno), caso 30 en `ProgramarApiTest`, `CsrfTest` |
| **11.5 Sesiones** | ✔ | Cookie `Secure`, `HttpOnly` y `SameSite=Strict` en todos los entornos, sin caducidad propia. `use_strict_mode`, `use_only_cookies`. En la cookie solo va el id; el id se regenera al entrar. La sesión caduca tras 30 min sin actividad o a las 8 h. Al salir se destruye la sesión y se borra la cookie. Sin cookie no se crea sesión | `AutenticacionTest` (atributos, regeneración, id inventado, logout y reutilización de la cookie antigua), `CaducidadSesionTest` |
| **11.6 Autorización** | ✔ | El router tiene una tabla ruta → roles y da 404 a lo que no está en ella. Una ruta privada no se puede declarar sin roles conocidos. `Permisos` comprueba que la visita es del técnico. Al técnico se le fuerza su `tecnico_id`. En cada petición el usuario se vuelve a leer de la base, así que un usuario desactivado pierde el acceso | `PermisosTest`, `RouterTest`, casos 25–27 en la suite `api`, agenda de otro técnico → 403 |
| **11.7 Secretos** | ✔ | `password_hash(PASSWORD_DEFAULT)` y `password_verify`. Con un email inexistente se compara contra un hash ficticio, para que tarde lo mismo. `#[SensitiveParameter]` en la contraseña; en producción las trazas no llevan argumentos. Los secretos solo están en `.env` (ignorado por git) o en el panel de Render; `.env` tampoco entra en la imagen | `CrearUsuariosDemoTest`, `check-security.sh`, revisión del historial (abajo), `DespliegueTest` |
| **11.8 SSRF** | ✔ | La aplicación no hace peticiones salientes: en `src/` y `public/` solo se lee el cuerpo de la petición y el `index.html` local. En producción, `allow_url_fopen=Off`. El único enlace externo es al mapa de OpenStreetMap, que abre el usuario; la página no lo carga | Búsqueda en el código (abajo), `InterfazTest` (solo ese enlace externo) |
| **11.9 Archivos** | ✔ | Sin subida de archivos (en producción, `file_uploads=Off`). El router no traduce rutas a ficheros. Solo `public/` es accesible desde la web, en desarrollo y en producción. `Options -Indexes` | `RouterTest` y `AutenticacionTest`: `/../src/Auth.php`, `/src/Auth.php` e `/index.php/../../src/Auth.php` → 404 sin código |
| **11.10 Errores** | ✔ | `display_errors` desactivado en desarrollo y en producción. `ManejadorErrores` envía el detalle a `error_log` y responde 500 con `{"errores":[{"regla":"INTERNO","mensaje":"Ha ocurrido un error. Inténtalo de nuevo."}]}`. El login responde siempre "Usuario o contraseña incorrectos" | `ManejadorErroresTest`, `AutenticacionTest` (mismo 401 para email existente, inexistente o inactivo). La suite `api` falla si el servidor registra cualquier aviso de PHP |

## Comprobaciones hechas en esta fase

- **Historial de git:** se han revisado todos los commits. `.env` no aparece en ninguno. Los patrones de secretos de `check-security.sh` no aparecen en ninguna línea añadida, salvo en `.env.example`, que solo tiene valores de ejemplo.
- **Peticiones salientes:** en `src/` y `public/` no hay `curl`, sockets ni URLs. Los dos únicos `file_get_contents` leen `php://input` y `public/index.html`.
- **Clon limpio:** siguiendo el README, en 1 min 18 s, con `composer verify` en verde (400 tests).

## Riesgos aceptados

- **Login sin límite de intentos.** Queda pendiente; la propuesta está en el análisis, sección 10.
- **Sesiones en archivos del contenedor.** Se pierden al redesplegar.
- **Usuarios demo públicos.** Su contraseña está solo en el panel de Render y no hay datos reales.
