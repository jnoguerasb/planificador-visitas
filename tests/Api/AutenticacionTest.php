<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTest;
use PHPUnit\Framework\Attributes\TestDox;

/** Login, sesión, CSRF, cabeceras y router de punta a punta por HTTP. */
final class AutenticacionTest extends ApiTest
{
    private const INCORRECTO = ['errores' => [['regla' => 'PERMISO', 'mensaje' => 'Usuario o contraseña incorrectos']]];
    private const SIN_SESION = ['errores' => [['regla' => 'PERMISO', 'mensaje' => 'Inicia sesión para continuar']]];

    // --- Login ---

    #[TestDox('Login correcto sin token CSRF: 200, usuario, token nuevo y cookie Secure, HttpOnly y SameSite=Strict')]
    public function testLoginCorrecto(): void
    {
        $respuesta = $this->postJson('/api/login', ['email' => 'planificador@test.local', 'password' => self::$clave]);

        self::assertSame(200, $respuesta['estado'], $respuesta['cuerpo']);
        self::assertSame(
            ['id' => 1, 'email' => 'planificador@test.local', 'rol' => 'PLANIFICADOR', 'tecnico_id' => null, 'tecnico' => null],
            $respuesta['json']['usuario']
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $respuesta['json']['csrf']);

        $cookies = $this->cookiesDeSesion($respuesta);
        self::assertCount(1, $cookies);
        $atributos = array_map('strtolower', array_map('trim', explode(';', $cookies[0])));
        foreach (['secure', 'httponly', 'samesite=strict', 'path=/'] as $atributo) {
            self::assertContains($atributo, $atributos, $cookies[0]);
        }
        foreach ($atributos as $atributo) {
            self::assertStringNotContainsString('expires', $atributo, 'Cookie de sesión: sin caducidad propia en el navegador');
        }
    }

    #[TestDox('El login de un técnico devuelve su técnico asociado')]
    public function testLoginDeUnTecnico(): void
    {
        $respuesta = $this->postJson('/api/login', ['email' => 'ana@test.local', 'password' => self::$clave]);

        self::assertSame(['id' => 2, 'email' => 'ana@test.local', 'rol' => 'TECNICO', 'tecnico_id' => 1, 'tecnico' => 'Ana Puig'], $respuesta['json']['usuario']);
    }

    #[TestDox('El email no distingue mayúsculas')]
    public function testEmailSinDistinguirMayusculas(): void
    {
        self::assertSame(200, $this->postJson('/api/login', ['email' => 'ANA@test.local', 'password' => self::$clave])['estado']);
    }

    #[TestDox('Al iniciar sesión el id de sesión cambia, también si el navegador traía uno inventado')]
    public function testElIdDeSesionSeRegenera(): void
    {
        $this->cookies[self::COOKIE_SESION] = str_repeat('a', 32);
        $this->entrar('ana@test.local');
        $primero = $this->cookies[self::COOKIE_SESION] ?? '';

        $this->entrar('ana@test.local');
        $segundo = $this->cookies[self::COOKIE_SESION] ?? '';

        self::assertNotSame('', $primero);
        self::assertNotSame(str_repeat('a', 32), $primero);
        self::assertNotSame($primero, $segundo);
    }

    #[TestDox('Cada login genera un token CSRF distinto')]
    public function testCadaLoginGeneraUnTokenNuevo(): void
    {
        self::assertNotSame($this->entrar('ana@test.local'), $this->entrar('ana@test.local'));
    }

    #[TestDox('Login incorrecto con email existente, inexistente o usuario inactivo: el mismo 401 y el mismo mensaje')]
    public function testLoginIncorrectoNoRevelaSiElEmailExiste(): void
    {
        $intentos = [
            'contraseña incorrecta' => ['email' => 'ana@test.local', 'password' => 'incorrecta'],
            'email inexistente' => ['email' => 'nadie@test.local', 'password' => self::$clave],
            'usuario inactivo' => ['email' => 'inactivo@test.local', 'password' => self::$clave],
        ];
        foreach ($intentos as $caso => $datos) {
            $respuesta = $this->postJson('/api/login', $datos);

            self::assertSame(401, $respuesta['estado'], $caso);
            self::assertSame(self::INCORRECTO, $respuesta['json'], $caso);
            self::assertSame([], $this->cookiesDeSesion($respuesta), "{$caso}: sin sesión nueva");
        }
    }

    #[TestDox('Login con Origin de otro sitio: 403; con el Origin propio: 200')]
    public function testLoginConOrigenAjeno(): void
    {
        $datos = ['email' => 'ana@test.local', 'password' => self::$clave];

        $ajeno = $this->postJson('/api/login', $datos, ['Origin' => 'https://atacante.example']);
        self::assertSame(403, $ajeno['estado']);
        self::assertSame([], $this->cookiesDeSesion($ajeno));

        self::assertSame(200, $this->postJson('/api/login', $datos, ['Origin' => 'http://localhost:' . self::PUERTO])['estado']);
    }

    #[TestDox('Login que no es JSON (formulario o JSON mal formado): 400; campos de más o de tipo incorrecto: 422')]
    public function testLoginConEntradaNoValida(): void
    {
        $formulario = $this->peticion('POST', '/api/login', 'email=ana%40test.local', ['Content-Type' => 'application/x-www-form-urlencoded']);
        self::assertSame(400, $formulario['estado']);
        self::assertSame('CAMPOS', $formulario['json']['errores'][0]['regla']);

        self::assertSame(400, $this->peticion('POST', '/api/login', '{"email":', ['Content-Type' => 'application/json'])['estado']);

        $deMas = $this->postJson('/api/login', ['email' => 'ana@test.local', 'password' => self::$clave, 'rol' => 'PLANIFICADOR']);
        self::assertSame(422, $deMas['estado']);
        self::assertSame([], $this->cookiesDeSesion($deMas));

        self::assertSame(422, $this->postJson('/api/login', ['email' => ['ana@test.local'], 'password' => self::$clave])['estado']);
        self::assertSame(422, $this->postJson('/api/login', ['email' => 'ana@test.local'])['estado']);
    }

    // --- Sesión ---

    #[TestDox('GET /api/yo sin sesión: 401 y sin crear sesión')]
    public function testYoSinSesion(): void
    {
        $respuesta = $this->peticion('GET', '/api/yo');

        self::assertSame(401, $respuesta['estado']);
        self::assertSame(self::SIN_SESION, $respuesta['json']);
        self::assertSame([], $this->cookiesDeSesion($respuesta));
    }

    #[TestDox('GET /api/yo con una cookie inventada: 401')]
    public function testYoConCookieInventada(): void
    {
        $this->cookies[self::COOKIE_SESION] = str_repeat('b', 32);

        self::assertSame(401, $this->peticion('GET', '/api/yo')['estado']);
    }

    #[TestDox('GET /api/yo con sesión: el usuario y el mismo token CSRF del login (para recargar la página)')]
    public function testYoConSesion(): void
    {
        $token = $this->entrar('ana@test.local');

        $respuesta = $this->peticion('GET', '/api/yo');

        self::assertSame(200, $respuesta['estado']);
        self::assertSame('TECNICO', $respuesta['json']['usuario']['rol']);
        self::assertSame($token, $respuesta['json']['csrf']);
    }

    // --- CSRF y logout ---

    #[TestDox('Logout sin token CSRF o con token alterado: 403 y la sesión sigue abierta')]
    public function testLogoutSinTokenValido(): void
    {
        $token = $this->entrar('ana@test.local');

        self::assertSame(403, $this->peticion('POST', '/api/logout')['estado']);
        $alterado = $this->peticion('POST', '/api/logout', null, ['X-CSRF-Token' => substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a')]);
        self::assertSame(403, $alterado['estado']);
        self::assertSame('PERMISO', $alterado['json']['errores'][0]['regla']);

        self::assertSame(200, $this->peticion('GET', '/api/yo')['estado']);
    }

    #[TestDox('El token CSRF de otra sesión no sirve')]
    public function testTokenDeOtraSesion(): void
    {
        $tokenDeAna = $this->entrar('ana@test.local');
        $this->cookies = [];
        $this->entrar('bernat@test.local');

        self::assertSame(403, $this->peticion('POST', '/api/logout', null, ['X-CSRF-Token' => $tokenDeAna])['estado']);
    }

    #[TestDox('Logout con token: 204, se borra la cookie y después /api/yo da 401 aunque se reenvíe la cookie antigua')]
    public function testLogout(): void
    {
        $token = $this->entrar('ana@test.local');
        $cookieAntigua = $this->cookies[self::COOKIE_SESION];

        $respuesta = $this->peticion('POST', '/api/logout', null, ['X-CSRF-Token' => $token]);

        self::assertSame(204, $respuesta['estado']);
        self::assertSame('', $respuesta['cuerpo']);
        self::assertArrayNotHasKey(self::COOKIE_SESION, $this->cookies, 'La respuesta debe borrar la cookie');
        self::assertSame(401, $this->peticion('GET', '/api/yo')['estado']);

        $this->cookies[self::COOKIE_SESION] = $cookieAntigua;
        self::assertSame(401, $this->peticion('GET', '/api/yo')['estado']);
    }

    #[TestDox('Logout sin sesión: 401')]
    public function testLogoutSinSesion(): void
    {
        self::assertSame(401, $this->peticion('POST', '/api/logout')['estado']);
    }

    // --- Router y cabeceras ---

    #[TestDox('Rutas que no están en la tabla: 404, sin servir código fuente')]
    public function testRutasInexistentes(): void
    {
        foreach (['GET /api/nada', 'GET /api/login', 'DELETE /api/yo', 'GET /../src/Auth.php', 'GET /src/Auth.php', 'GET /index.php/../../src/Auth.php'] as $caso) {
            [$metodo, $ruta] = explode(' ', $caso);
            $respuesta = $this->peticion($metodo, $ruta);

            self::assertSame(404, $respuesta['estado'], $caso);
            self::assertStringNotContainsString('<?php', $respuesta['cuerpo'], $caso);
            self::assertStringNotContainsString('namespace', $respuesta['cuerpo'], $caso);
        }
    }

    #[TestDox('Las cabeceras de seguridad están en todas las respuestas, también en las de error y en la página')]
    public function testCabecerasDeSeguridadEnTodasLasRespuestas(): void
    {
        $token = $this->entrar('ana@test.local');
        $respuestas = [
            'página' => $this->peticion('GET', '/'),
            '200' => $this->peticion('GET', '/api/yo'),
            '400' => $this->peticion('POST', '/api/login', 'x', ['Content-Type' => 'text/plain']),
            '403' => $this->peticion('POST', '/api/logout'),
            '404' => $this->peticion('GET', '/api/nada'),
            '204' => $this->peticion('POST', '/api/logout', null, ['X-CSRF-Token' => $token]),
            '401' => $this->peticion('GET', '/api/yo'),
        ];

        foreach ($respuestas as $caso => $respuesta) {
            // PHP convierte las claves '200', '404'... en enteros.
            $this->assertCabecerasDeSeguridad($respuesta, (string) $caso);
            if ($caso !== 'página') {
                self::assertSame(['no-store'], $respuesta['cabeceras']['cache-control'] ?? [], (string) $caso);
            }
        }
        self::assertSame(200, $respuestas['página']['estado']);
        self::assertStringStartsWith('text/html', $respuestas['página']['cabeceras']['content-type'][0] ?? '');
        self::assertStringStartsWith('application/json', $respuestas['401']['cabeceras']['content-type'][0] ?? '');
    }

    #[TestDox('Las respuestas no revelan la versión de PHP')]
    public function testNoRevelaLaVersionDePhp(): void
    {
        self::assertArrayNotHasKey('x-powered-by', $this->peticion('GET', '/api/yo')['cabeceras']);
    }
}
