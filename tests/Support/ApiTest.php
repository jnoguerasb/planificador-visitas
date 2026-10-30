<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

/**
 * Clase base de la suite api: peticiones HTTP reales contra `php -S localhost:8099 -t public`.
 *
 * Antes de cada clase recrea planificador_test (esquema + seed), crea los usuarios de prueba con
 * una contraseña aleatoria y arranca el servidor con DATABASE_URL apuntando a la base de tests.
 * Las cookies se guardan a mano para poder comprobar sus atributos (Secure, HttpOnly, SameSite).
 */
abstract class ApiTest extends BaseDatosTest
{
    protected const PUERTO = 8099;
    protected const COOKIE_SESION = 'planificador_sesion';

    /** Contraseña de todos los usuarios de prueba; aleatoria, nunca escrita en el código. */
    protected static string $clave = '';

    /** @var resource|null */
    private static $servidor = null;
    private static string $registroServidor = '';

    /** @var array<string, string> Cookies vigentes del "navegador" de cada test. */
    protected array $cookies = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$clave = bin2hex(random_bytes(12));
        $sentencia = self::$admin?->prepare(<<<'SQL'
            INSERT INTO usuarios (id, email, password_hash, rol, tecnico_id, activo) VALUES
                (1, 'planificador@test.local', ?, 'PLANIFICADOR', NULL, TRUE),
                (2, 'ana@test.local',          ?, 'TECNICO',      1,    TRUE),
                (3, 'bernat@test.local',       ?, 'TECNICO',      2,    TRUE),
                (4, 'inactivo@test.local',     ?, 'TECNICO',      3,    FALSE)
            SQL);
        $sentencia?->execute(array_fill(0, 4, password_hash(self::$clave, PASSWORD_DEFAULT)));

        self::arrancarServidor();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
            self::$servidor = null;
        }
        if (self::$registroServidor !== '' && is_file(self::$registroServidor)) {
            unlink(self::$registroServidor);
        }
        parent::tearDownAfterClass();
    }

    // El servidor lee de la base con su propia conexión: aquí no hay transacción que deshacer.
    protected function setUp(): void
    {
        $this->cookies = [];
    }

    protected function tearDown(): void
    {
    }

    private static function arrancarServidor(): void
    {
        if (self::puertoAbierto()) {
            throw new RuntimeException('El puerto ' . self::PUERTO . ' ya está ocupado: cierra el programa que lo usa');
        }

        $entorno = getenv();
        $entorno['DATABASE_URL'] = self::urlDeEntorno('DATABASE_URL_TEST');
        self::$registroServidor = (string) tempnam(sys_get_temp_dir(), 'planificador_api_');

        $raiz = dirname(__DIR__, 2);
        $proceso = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', 'localhost:' . self::PUERTO, '-t', $raiz . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$registroServidor, 'a'], 2 => ['file', self::$registroServidor, 'a']],
            $tuberias,
            $raiz,
            $entorno
        );
        if ($proceso === false) {
            throw new RuntimeException('No se ha podido arrancar el servidor de pruebas');
        }
        fclose($tuberias[0]);
        self::$servidor = $proceso;

        $limite = microtime(true) + 10;
        while (!self::puertoAbierto()) {
            if (microtime(true) > $limite) {
                throw new RuntimeException('El servidor de pruebas no arranca: ' . file_get_contents(self::$registroServidor));
            }
            usleep(100_000);
        }
    }

    private static function puertoAbierto(): bool
    {
        $conexion = @fsockopen('localhost', self::PUERTO, $codigo, $mensaje, 0.2);
        if ($conexion === false) {
            return false;
        }
        fclose($conexion);

        return true;
    }

    /**
     * Petición HTTP. Envía las cookies guardadas y guarda las que lleguen.
     *
     * @param array<string, string> $cabeceras
     * @return array{estado: int, cabeceras: array<string, list<string>>, cuerpo: string, json: mixed}
     */
    protected function peticion(string $metodo, string $ruta, ?string $cuerpo = null, array $cabeceras = []): array
    {
        if ($this->cookies !== []) {
            $pares = [];
            foreach ($this->cookies as $nombre => $valor) {
                $pares[] = "{$nombre}={$valor}";
            }
            $cabeceras['Cookie'] = implode('; ', $pares);
        }

        $recibidas = [];
        $curl = curl_init('http://localhost:' . self::PUERTO . $ruta);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_HTTPHEADER => array_map(fn (string $n, string $v) => "{$n}: {$v}", array_keys($cabeceras), $cabeceras),
            CURLOPT_HEADERFUNCTION => function ($curl, string $linea) use (&$recibidas): int {
                $partes = explode(':', $linea, 2);
                if (count($partes) === 2) {
                    $recibidas[strtolower(trim($partes[0]))][] = trim($partes[1]);
                }

                return strlen($linea);
            },
        ]);
        if ($cuerpo !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $respuesta = curl_exec($curl);
        if ($respuesta === false) {
            throw new RuntimeException('Fallo de red con el servidor de pruebas: ' . curl_error($curl));
        }
        $estado = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        foreach ($recibidas['set-cookie'] ?? [] as $cookie) {
            $this->guardarCookie($cookie);
        }

        return ['estado' => $estado, 'cabeceras' => $recibidas, 'cuerpo' => (string) $respuesta, 'json' => json_decode((string) $respuesta, true)];
    }

    /**
     * POST con cuerpo JSON.
     *
     * @param array<string, mixed> $datos
     * @param array<string, string> $cabeceras
     * @return array{estado: int, cabeceras: array<string, list<string>>, cuerpo: string, json: mixed}
     */
    protected function postJson(string $ruta, array $datos, array $cabeceras = []): array
    {
        return $this->peticion('POST', $ruta, (string) json_encode($datos), $cabeceras + ['Content-Type' => 'application/json']);
    }

    /** Inicia sesión y devuelve el token CSRF. */
    protected function entrar(string $email): string
    {
        $respuesta = $this->postJson('/api/login', ['email' => $email, 'password' => self::$clave]);
        self::assertSame(200, $respuesta['estado'], $respuesta['cuerpo']);

        return $respuesta['json']['csrf'];
    }

    /**
     * Atributos de la cookie de sesión tal como llegó en la respuesta.
     *
     * @param array{cabeceras: array<string, list<string>>} $respuesta
     * @return list<string>
     */
    protected function cookiesDeSesion(array $respuesta): array
    {
        return array_values(array_filter(
            $respuesta['cabeceras']['set-cookie'] ?? [],
            fn (string $c) => str_starts_with($c, self::COOKIE_SESION . '=')
        ));
    }

    private function guardarCookie(string $cabecera): void
    {
        $partes = array_map('trim', explode(';', $cabecera));
        [$nombre, $valor] = array_pad(explode('=', (string) array_shift($partes), 2), 2, '');

        $borrada = $valor === '' || $valor === 'deleted';
        foreach ($partes as $atributo) {
            if (preg_match('/^max-age=(-?\d+)$/i', $atributo, $m) === 1 && (int) $m[1] <= 0) {
                $borrada = true;
            }
            if (preg_match('/^expires=(.+)$/i', $atributo, $m) === 1 && strtotime($m[1]) < time()) {
                $borrada = true;
            }
        }

        if ($borrada) {
            unset($this->cookies[$nombre]);
        } else {
            $this->cookies[$nombre] = $valor;
        }
    }

    /**
     * Las cuatro cabeceras de seguridad exigidas en todas las respuestas.
     *
     * @param array{cabeceras: array<string, list<string>>} $respuesta
     */
    protected function assertCabecerasDeSeguridad(array $respuesta, string $contexto = ''): void
    {
        $esperadas = [
            'content-security-policy' => "default-src 'self'",
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'same-origin',
            'x-frame-options' => 'DENY',
        ];
        foreach ($esperadas as $nombre => $valor) {
            self::assertSame([$valor], $respuesta['cabeceras'][$nombre] ?? [], "{$nombre} {$contexto}");
        }
    }
}
