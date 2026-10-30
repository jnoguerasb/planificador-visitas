<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\BaseDatosTest;
use RuntimeException;

/**
 * bin/crear_usuarios_demo.php ejecutado como proceso aparte contra planificador_test,
 * con el usuario de la aplicación. Escribe con commit propio, así que estos tests no usan
 * la transacción de la clase base: vacían la tabla de usuarios antes de cada test.
 */
final class CrearUsuariosDemoTest extends BaseDatosTest
{
    private const EMAILS = [
        'ana@demo.local',
        'bernat@demo.local',
        'carla@demo.local',
        'david@demo.local',
        'planificador@demo.local',
    ];

    protected function setUp(): void
    {
        $this->admin()->exec('DELETE FROM usuarios');
    }

    protected function tearDown(): void
    {
    }

    /** Contraseña aleatoria para cada prueba: nunca una contraseña escrita en el código. */
    private static function claveAleatoria(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, string|null> $variables null quita la variable del entorno
     * @return array{codigo: int, salida: string, errores: string}
     */
    private function ejecutarScript(array $variables): array
    {
        $entorno = getenv();
        $entorno['DATABASE_URL'] = self::urlDeEntorno('DATABASE_URL_TEST');
        foreach ($variables as $nombre => $valor) {
            if ($valor === null) {
                unset($entorno[$nombre]);
            } else {
                $entorno[$nombre] = $valor;
            }
        }

        $proceso = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/crear_usuarios_demo.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuberias,
            null,
            $entorno
        );
        if ($proceso === false) {
            throw new RuntimeException('No se ha podido ejecutar crear_usuarios_demo.php');
        }
        $salida = (string) stream_get_contents($tuberias[1]);
        $errores = (string) stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);

        return ['codigo' => proc_close($proceso), 'salida' => $salida, 'errores' => $errores];
    }

    /** @return array<string, array{rol: string, tecnico_id: ?int, password_hash: string}> */
    private function usuarios(): array
    {
        $filas = $this->admin()->query('SELECT email, rol, tecnico_id, password_hash FROM usuarios ORDER BY email')->fetchAll();
        $porEmail = [];
        foreach ($filas as $fila) {
            $porEmail[$fila['email']] = $fila;
        }

        return $porEmail;
    }

    public function testFallaConMensajeClaroSiFaltaDemoPassword(): void
    {
        $resultado = $this->ejecutarScript(['DEMO_PASSWORD' => null]);

        self::assertNotSame(0, $resultado['codigo']);
        self::assertStringContainsString('falta la variable de entorno DEMO_PASSWORD', $resultado['errores']);
        self::assertSame(0, $this->valor('SELECT COUNT(*) FROM usuarios'));
    }

    public function testFallaSiDemoPasswordEstaVacia(): void
    {
        $resultado = $this->ejecutarScript(['DEMO_PASSWORD' => '']);

        self::assertNotSame(0, $resultado['codigo']);
        self::assertStringContainsString('DEMO_PASSWORD', $resultado['errores']);
        self::assertSame(0, $this->valor('SELECT COUNT(*) FROM usuarios'));
    }

    public function testCreaUnPlanificadorYUnUsuarioPorTecnico(): void
    {
        $resultado = $this->ejecutarScript(['DEMO_PASSWORD' => self::claveAleatoria()]);

        self::assertSame(0, $resultado['codigo'], $resultado['errores']);
        $usuarios = $this->usuarios();
        self::assertSame(self::EMAILS, array_keys($usuarios));
        self::assertSame('PLANIFICADOR', $usuarios['planificador@demo.local']['rol']);
        self::assertNull($usuarios['planificador@demo.local']['tecnico_id']);
        self::assertSame('TECNICO', $usuarios['ana@demo.local']['rol']);
        self::assertSame(1, $usuarios['ana@demo.local']['tecnico_id']);
        self::assertSame(2, $usuarios['bernat@demo.local']['tecnico_id']);
    }

    public function testEsIdempotente(): void
    {
        $clave = self::claveAleatoria();

        $primera = $this->ejecutarScript(['DEMO_PASSWORD' => $clave]);
        $hashesAntes = array_column($this->usuarios(), 'password_hash', 'email');
        $segunda = $this->ejecutarScript(['DEMO_PASSWORD' => $clave]);
        $hashesDespues = array_column($this->usuarios(), 'password_hash', 'email');

        self::assertSame(0, $primera['codigo'], $primera['errores']);
        self::assertSame(0, $segunda['codigo'], $segunda['errores']);
        self::assertCount(5, $hashesDespues);
        self::assertSame($hashesAntes, $hashesDespues);
        self::assertStringContainsString('0 creados', $segunda['salida']);
    }

    public function testGuardaHashesYNuncaLaContrasenaEnClaro(): void
    {
        $clave = self::claveAleatoria();

        $resultado = $this->ejecutarScript(['DEMO_PASSWORD' => $clave]);

        self::assertSame(0, $resultado['codigo'], $resultado['errores']);
        foreach ($this->usuarios() as $email => $usuario) {
            self::assertNotSame($clave, $usuario['password_hash'], $email);
            self::assertStringNotContainsString($clave, $usuario['password_hash'], $email);
            self::assertNotSame('unknown', password_get_info($usuario['password_hash'])['algoName'], $email);
            self::assertTrue(password_verify($clave, $usuario['password_hash']), $email);
        }
        self::assertStringNotContainsString($clave, $resultado['salida']);
    }

    public function testSiCambiaDemoPasswordActualizaElHash(): void
    {
        $this->ejecutarScript(['DEMO_PASSWORD' => self::claveAleatoria()]);
        $nueva = self::claveAleatoria();

        $resultado = $this->ejecutarScript(['DEMO_PASSWORD' => $nueva]);

        self::assertSame(0, $resultado['codigo'], $resultado['errores']);
        self::assertStringContainsString('5 con contraseña actualizada', $resultado['salida']);
        foreach ($this->usuarios() as $email => $usuario) {
            self::assertTrue(password_verify($nueva, $usuario['password_hash']), $email);
        }
    }
}
