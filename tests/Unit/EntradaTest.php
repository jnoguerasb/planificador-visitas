<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entrada;
use App\ErrorHttp;
use App\ErrorRegla;
use App\ErroresDeNegocio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntradaTest extends TestCase
{
    private const JSON = 'application/json';

    // --- Cuerpo JSON ---

    public function testLeeUnObjetoJsonConLosCamposPermitidos(): void
    {
        $datos = Entrada::json(self::JSON, '{"email":"ana@demo.local","password":"x","edad":3}', ['email', 'password', 'edad']);

        self::assertSame(['email' => 'ana@demo.local', 'password' => 'x', 'edad' => 3], $datos);
    }

    public function testNoExigeTodosLosCamposPermitidos(): void
    {
        self::assertSame(['email' => 'a'], Entrada::json(self::JSON, '{"email":"a"}', ['email', 'password']));
        self::assertSame([], Entrada::json(self::JSON, '{}', ['email']));
    }

    public function testAceptaCharsetUtf8EnElContentType(): void
    {
        self::assertSame(['a' => 1], Entrada::json('application/json; charset=UTF-8', '{"a":1}', ['a']));
        self::assertSame(['a' => 1], Entrada::json('Application/JSON', '{"a":1}', ['a']));
    }

    public function testRechazaCamposNoEsperadosCon422(): void
    {
        try {
            Entrada::json(self::JSON, '{"email":"a","rol":"PLANIFICADOR","":1}', ['email']);
            self::fail('Se esperaban errores de CAMPOS');
        } catch (ErroresDeNegocio $e) {
            self::assertSame([ErrorRegla::CAMPOS, ErrorRegla::CAMPOS], $e->reglas());
            self::assertSame(['rol', ''], array_map(fn (ErrorRegla $error) => $error->campo, $e->errores));
            self::assertSame('Campo no permitido: rol', $e->errores[0]->mensaje);
        }
    }

    /** @return array<string, array{?string, string}> */
    public static function peticionesIlegibles(): array
    {
        return [
            'sin Content-Type' => [null, '{"a":1}'],
            'texto plano' => ['text/plain', '{"a":1}'],
            'formulario' => ['application/x-www-form-urlencoded', 'a=1'],
            'otro charset' => ['application/json; charset=latin1', '{"a":1}'],
            'JSON mal formado' => [self::JSON, '{"a":1'],
            'cuerpo vacío' => [self::JSON, ''],
            'lista en vez de objeto' => [self::JSON, '[1,2]'],
            'número' => [self::JSON, '42'],
            'texto JSON' => [self::JSON, '"hola"'],
            'nulo' => [self::JSON, 'null'],
            'UTF-8 inválido' => [self::JSON, "{\"a\":\"\xC3\x28\"}"],
            'demasiado anidado' => [self::JSON, str_repeat('{"a":', 20) . '1' . str_repeat('}', 20)],
            'demasiado grande' => [self::JSON, '{"a":"' . str_repeat('x', Entrada::TAMANO_MAXIMO) . '"}'],
        ];
    }

    #[DataProvider('peticionesIlegibles')]
    public function testPeticionIlegibleDa400(?string $tipo, string $cuerpo): void
    {
        try {
            Entrada::json($tipo, $cuerpo, ['a']);
            self::fail('Se esperaba un 400');
        } catch (ErrorHttp $e) {
            self::assertSame(400, $e->estado);
            self::assertSame(ErrorRegla::CAMPOS, $e->regla);
        }
    }

    public function testLosValoresAnidadosNoSonTextosNiNumeros(): void
    {
        $datos = Entrada::json(self::JSON, '{"a":{"b":1},"c":[1]}', ['a', 'c']);

        self::assertIsNotString($datos['a']);
        self::assertIsNotInt($datos['a']);
        self::assertIsNotString($datos['c']);
    }

    // --- Parámetros de la URL ---

    public function testParametrosPermitidosYVaciosComoAusentes(): void
    {
        self::assertSame(
            ['fecha' => '2030-01-15'],
            Entrada::parametros(['fecha' => '2030-01-15', 'estado' => ''], ['fecha', 'estado', 'tecnico_id'])
        );
    }

    public function testParametroNoEsperadoDa422(): void
    {
        $this->expectException(ErroresDeNegocio::class);
        Entrada::parametros(['fecha' => '2030-01-15', 'orden' => 'x'], ['fecha']);
    }

    public function testParametroRepetidoComoListaDa422(): void
    {
        $this->expectException(ErroresDeNegocio::class);
        Entrada::parametros(['fecha' => ['2030-01-15', '2030-01-16']], ['fecha']);
    }

    /** @return array<string, array{?string, ?int}> */
    public static function enteros(): array
    {
        return [
            'ausente' => [null, null],
            'uno' => ['1', 1],
            'varias cifras' => ['2147483647', 2147483647],
            'mayor que INT de PostgreSQL (lo rechaza el servicio)' => ['99999999999', 99999999999],
        ];
    }

    #[DataProvider('enteros')]
    public function testEnteroValido(?string $texto, ?int $esperado): void
    {
        self::assertSame($esperado, Entrada::entero($texto, 'tecnico_id', 'Elige un técnico válido'));
    }

    /** @return array<string, array{string}> */
    public static function enterosNoValidos(): array
    {
        return [
            'cero' => ['0'],
            'negativo' => ['-1'],
            'cero a la izquierda' => ['01'],
            'decimal' => ['1.5'],
            'con espacios' => [' 1'],
            'exponente' => ['1e3'],
            'texto' => ['uno'],
            'desborda PHP' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('enterosNoValidos')]
    public function testEnteroNoValidoDaCampos(string $texto): void
    {
        try {
            Entrada::entero($texto, 'tecnico_id', 'Elige un técnico válido');
            self::fail('Se esperaba CAMPOS');
        } catch (ErroresDeNegocio $e) {
            self::assertSame([[ErrorRegla::CAMPOS, 'Elige un técnico válido', 'tecnico_id']], array_map(
                fn (ErrorRegla $error) => [$error->regla, $error->mensaje, $error->campo],
                $e->errores
            ));
        }
    }
}
