<?php

declare(strict_types=1);

// Punto de entrada único: toda petición que no es un archivo de public/ llega aquí.
// La tabla de rutas dice quién puede usar cada una; lo que no está en la tabla da 404.

use App\Auth;
use App\CatalogoRepository;
use App\ControladorCatalogo;
use App\ControladorVisitas;
use App\Database;
use App\ErrorHttp;
use App\ManejadorErrores;
use App\Peticion;
use App\Reloj;
use App\Respuesta;
use App\Router;
use App\Ruta;
use App\Usuario;
use App\UsuarioRepository;
use App\VisitaService;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '0');
date_default_timezone_set(Reloj::ZONA_HORARIA);

$manejador = new ManejadorErrores();
$manejador->registrarGlobalmente();
Auth::configurar();

try {
    // La conexión se abre solo si alguna ruta la necesita.
    $pdo = null;
    $conexion = static function () use (&$pdo): PDO {
        return $pdo ??= Database::desdeEntorno('DATABASE_URL');
    };
    $auth = new Auth(static fn (): UsuarioRepository => new UsuarioRepository($conexion()));
    $catalogo = static fn (): ControladorCatalogo => new ControladorCatalogo(new CatalogoRepository($conexion()));
    $visitas = static fn (): ControladorVisitas => new ControladorVisitas(new VisitaService($conexion()), new CatalogoRepository($conexion()));

    $planificador = [Usuario::PLANIFICADOR];
    $tecnico = [Usuario::TECNICO];
    $todos = [Usuario::PLANIFICADOR, Usuario::TECNICO];
    // Id de visita en la ruta: de 1 a 18 cifras, sin ceros a la izquierda (cabe en un entero de PHP).
    $id = '(?<id>[1-9][0-9]{0,17})';

    $rutas = [
        Ruta::publica('GET', '#^/$#', static fn (): Respuesta => Respuesta::html((string) file_get_contents(__DIR__ . '/index.html'))),

        // Sin token CSRF (lo compensan el JSON obligatorio, el control de Origin y SameSite=Strict). Devuelve el token nuevo.
        Ruta::publica('POST', '#^/api/login$#', static function (Peticion $p) use ($auth): Respuesta {
            $datos = $p->json(['email', 'password']);

            return Respuesta::json(200, $auth->iniciarSesion($datos['email'] ?? null, $datos['password'] ?? null));
        }, csrf: false),

        Ruta::para($todos, 'POST', '#^/api/logout$#', static function () use ($auth): Respuesta {
            $auth->cerrarSesion();

            return Respuesta::sinContenido();
        }),

        Ruta::para($todos, 'GET', '#^/api/yo$#', static function (Peticion $p) use ($auth): Respuesta {
            $perfil = $p->usuario === null ? null : $auth->perfilActual($p->usuario);

            return Respuesta::json(200, $perfil ?? throw ErrorHttp::noAutenticado());
        }),

        // Ruta, roles permitidos y acción. Lo que no está aquí da 404.
        Ruta::para($planificador, 'GET', '#^/api/tecnicos$#', static fn (Peticion $p) => $catalogo()->tecnicos($p)),
        Ruta::para($planificador, 'GET', '#^/api/obras$#', static fn (Peticion $p) => $catalogo()->obras($p)),
        Ruta::para($todos, 'GET', '#^/api/visitas$#', static fn (Peticion $p) => $visitas()->listar($p)),
        Ruta::para($todos, 'GET', '#^/api/agenda$#', static fn (Peticion $p) => $visitas()->agenda($p)),
        Ruta::para($planificador, 'POST', '#^/api/visitas/validar$#', static fn (Peticion $p) => $visitas()->validar($p)),
        Ruta::para($planificador, 'POST', '#^/api/visitas$#', static fn (Peticion $p) => $visitas()->programar($p)),
        Ruta::para($tecnico, 'POST', "#^/api/visitas/{$id}/completar$#", static fn (Peticion $p) => $visitas()->completar($p)),
        Ruta::para($todos, 'POST', "#^/api/visitas/{$id}/cancelar$#", static fn (Peticion $p) => $visitas()->cancelar($p)),
    ];

    $respuesta = (new Router($rutas, $auth))->atender(Peticion::desdeGlobales());
} catch (Throwable $error) {
    $respuesta = $manejador->respuestaPara($error);
}

$respuesta->enviar();
