<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth;
use App\ErrorHttp;
use App\Peticion;
use App\Respuesta;
use App\Router;
use App\Ruta;
use App\Usuario;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lo que el router decide sin sesión: qué ruta atiende, 404 por defecto y el control de Origin.
 * La sesión, los roles y el CSRF se prueban de punta a punta en la suite api.
 */
final class RouterTest extends TestCase
{
    public function testAtiendeLaRutaPublicaYPasaLosParametrosDeLaRuta(): void
    {
        $router = $this->router([
            Ruta::publica('GET', '#^/api/eco/(?<id>[1-9][0-9]*)$#', fn (Peticion $p) => Respuesta::json(200, ['id' => $p->parametros['id']])),
        ]);

        $respuesta = $router->atender($this->peticion('GET', '/api/eco/42'));

        self::assertSame(200, $respuesta->estado);
        self::assertSame('{"id":"42"}', $respuesta->cuerpo);
    }

    /** @return array<string, array{string, string}> */
    public static function rutasQueNoExisten(): array
    {
        return [
            'ruta desconocida' => ['GET', '/api/nada'],
            'método distinto' => ['POST', '/api/eco'],
            'subir de carpeta' => ['GET', '/../src/Auth.php'],
            'archivo de código' => ['GET', '/src/Auth.php'],
            'barra final' => ['GET', '/api/eco/'],
            'mayúsculas' => ['GET', '/API/ECO'],
        ];
    }

    #[DataProvider('rutasQueNoExisten')]
    public function testLoQueNoEstaEnLaTablaDa404(string $metodo, string $ruta): void
    {
        $router = $this->router([Ruta::publica('GET', '#^/api/eco$#', fn () => Respuesta::json(200, []))]);

        $this->expectExceptionObject(ErrorHttp::noEncontrado());
        $router->atender($this->peticion($metodo, $ruta));
    }

    public function testUnPostConOrigenAjenoDa403AunqueLaRutaSeaPublica(): void
    {
        $router = $this->router([Ruta::publica('POST', '#^/api/login$#', fn () => Respuesta::json(200, []), csrf: false)]);

        try {
            $router->atender($this->peticion('POST', '/api/login', ['origin' => 'https://atacante.example']));
            self::fail('Se esperaba un 403');
        } catch (ErrorHttp $e) {
            self::assertSame(403, $e->estado);
        }
        self::assertSame(200, $router->atender($this->peticion('POST', '/api/login', ['origin' => 'http://localhost:8081']))->estado);
        self::assertSame(200, $router->atender($this->peticion('POST', '/api/login'))->estado);
    }

    /** @return array<string, array{?string, string, bool}> */
    public static function origenes(): array
    {
        return [
            'sin cabecera Origin' => [null, 'localhost:8081', true],
            'mismo host y puerto' => ['http://localhost:8081', 'localhost:8081', true],
            'https detrás del proxy' => ['https://planificador.onrender.com', 'planificador.onrender.com', true],
            'mayúsculas' => ['https://Planificador.onrender.com', 'planificador.onrender.com', true],
            'otro puerto' => ['http://localhost:9999', 'localhost:8081', false],
            'otro dominio' => ['https://atacante.example', 'planificador.onrender.com', false],
            'subdominio del atacante' => ['https://planificador.onrender.com.atacante.example', 'planificador.onrender.com', false],
            'origen nulo' => ['null', 'localhost:8081', false],
            'vacío' => ['', 'localhost:8081', false],
            'sin host propio' => ['http://localhost:8081', '', false],
        ];
    }

    #[DataProvider('origenes')]
    public function testOrigenPermitido(?string $origen, string $host, bool $permitido): void
    {
        self::assertSame($permitido, Router::origenPermitido($origen, $host));
    }

    public function testUnaRutaPrivadaDebeDecirQueRolesPermite(): void
    {
        $this->expectException(LogicException::class);
        Ruta::para([], 'GET', '#^/api/yo$#', fn () => Respuesta::json(200, []));
    }

    public function testUnaRutaPrivadaSoloAdmiteRolesConocidos(): void
    {
        $this->expectException(LogicException::class);
        Ruta::para(['ADMIN'], 'GET', '#^/api/yo$#', fn () => Respuesta::json(200, []));
    }

    public function testUnaRutaPrivadaConRolesConocidosSeCrea(): void
    {
        self::assertFalse(Ruta::para([Usuario::PLANIFICADOR], 'GET', '#^/api/yo$#', fn () => Respuesta::json(200, []))->esPublica());
    }

    /** @param list<Ruta> $rutas */
    private function router(array $rutas): Router
    {
        // Estas rutas no necesitan sesión: si el router la pidiera, el test fallaría.
        return new Router($rutas, new Auth(fn () => throw new LogicException('No debería consultar usuarios')));
    }

    /** @param array<string, string> $cabeceras */
    private function peticion(string $metodo, string $ruta, array $cabeceras = []): Peticion
    {
        return new Peticion($metodo, $ruta, [], $cabeceras + ['host' => 'localhost:8081'], '');
    }
}
