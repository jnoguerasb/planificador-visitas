<?php

declare(strict_types=1);

// Punto de entrada único: toda petición que no es un archivo de public/ llega aquí.
// La tabla de rutas dice quién puede usar cada una; lo que no está en la tabla da 404.

use App\Auth;
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
    $todos = [Usuario::PLANIFICADOR, Usuario::TECNICO];

    $rutas = [
        Ruta::publica('GET', '#^/$#', static fn (): Respuesta => Respuesta::html((string) file_get_contents(__DIR__ . '/index.html'))),

        // Sin token CSRF: excepción aprobada (CLAUDE.md, premisa 4). Devuelve el token nuevo.
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
    ];

    $respuesta = (new Router($rutas, $auth))->atender(Peticion::desdeGlobales());
} catch (Throwable $error) {
    $respuesta = $manejador->respuestaPara($error);
}

$respuesta->enviar();
