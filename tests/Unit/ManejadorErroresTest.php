<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ErrorHttp;
use App\ErrorRegla;
use App\ErroresDeNegocio;
use App\ManejadorErrores;
use App\Respuesta;
use App\VisitaNoEncontrada;
use ErrorException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Traducción de excepciones a respuestas HTTP. Los errores inesperados se comprueban aquí,
 * pasando la excepción directamente: no hace falta provocar fallos en el servidor.
 */
final class ManejadorErroresTest extends TestCase
{
    /** @var list<string> */
    private array $registro = [];

    private function manejador(): ManejadorErrores
    {
        return new ManejadorErrores(function (string $linea): void {
            $this->registro[] = $linea;
        });
    }

    public function testUnErrorInesperadoDa500GenericoSinDetallesYLosRegistra(): void
    {
        $excepcion = new PDOException('SQLSTATE[42P01]: relation "visitas" does not exist at C:\proyectos\planificador_visitas\src\VisitaRepository.php SELECT * FROM visitas');

        $respuesta = $this->manejador()->respuestaPara($excepcion);

        self::assertSame(500, $respuesta->estado);
        self::assertSame(
            ['errores' => [['regla' => 'INTERNO', 'mensaje' => 'Ha ocurrido un error. Inténtalo de nuevo.']]],
            json_decode($respuesta->cuerpo, true)
        );
        foreach (['SQLSTATE', 'SELECT', 'proyectos', '.php', 'visitas', 'PDOException'] as $detalle) {
            self::assertStringNotContainsString($detalle, $respuesta->cuerpo);
        }

        self::assertCount(1, $this->registro);
        self::assertStringContainsString('SQLSTATE[42P01]', $this->registro[0]);
        self::assertStringContainsString('PDOException', $this->registro[0]);
    }

    public function testLosAvisosDePhpConvertidosEnExcepcionTambienDan500(): void
    {
        $respuesta = $this->manejador()->respuestaPara(new ErrorException('Undefined array key "x"', 0, E_WARNING, __FILE__, 10));

        self::assertSame(500, $respuesta->estado);
        self::assertStringNotContainsString('Undefined', $respuesta->cuerpo);
        self::assertCount(1, $this->registro);
    }

    public function testErroresDeCamposDan422ConTodosLosErroresYSinRegistrar(): void
    {
        $respuesta = $this->manejador()->respuestaPara(new ErroresDeNegocio([
            new ErrorRegla(ErrorRegla::CAMPOS, 'El desplazamiento mínimo es 30 min', 'desplazamiento_min'),
            new ErrorRegla(ErrorRegla::SOLAPE, 'Solapa con la visita de 10:00 a 11:00 en Obra A'),
        ]));

        self::assertSame(422, $respuesta->estado);
        self::assertSame(['errores' => [
            ['regla' => 'CAMPOS', 'mensaje' => 'El desplazamiento mínimo es 30 min', 'campo' => 'desplazamiento_min'],
            ['regla' => 'SOLAPE', 'mensaje' => 'Solapa con la visita de 10:00 a 11:00 en Obra A'],
        ]], json_decode($respuesta->cuerpo, true));
        self::assertSame([], $this->registro);
    }

    public function testPermisoDa403YEstadoDa409(): void
    {
        self::assertSame(403, $this->manejador()->respuestaPara(ErroresDeNegocio::una(ErrorRegla::PERMISO, 'No'))->estado);
        self::assertSame(409, $this->manejador()->respuestaPara(ErroresDeNegocio::una(ErrorRegla::ESTADO, 'La visita ya está Completada'))->estado);
        self::assertSame(422, $this->manejador()->respuestaPara(ErroresDeNegocio::una(ErrorRegla::TEXTO, 'Corto'))->estado);
    }

    public function testErrorHttpConservaSuEstadoYMensaje(): void
    {
        $respuesta = $this->manejador()->respuestaPara(ErrorHttp::noAutenticado());

        self::assertSame(401, $respuesta->estado);
        self::assertSame(['errores' => [['regla' => 'PERMISO', 'mensaje' => 'Inicia sesión para continuar']]], json_decode($respuesta->cuerpo, true));
        self::assertSame([], $this->registro);
    }

    public function testVisitaNoEncontradaDa404(): void
    {
        $respuesta = $this->manejador()->respuestaPara(new VisitaNoEncontrada(7));

        self::assertSame(404, $respuesta->estado);
        self::assertSame(['errores' => [['regla' => 'NO_ENCONTRADO', 'mensaje' => 'La visita no existe']]], json_decode($respuesta->cuerpo, true));
    }

    public function testTodasLasRespuestasLlevanLasCabecerasDeSeguridad(): void
    {
        foreach ([new RuntimeException('x'), ErrorHttp::noEncontrado()] as $excepcion) {
            $cabeceras = $this->manejador()->respuestaPara($excepcion)->cabecerasCompletas();

            self::assertSame("default-src 'self'", $cabeceras['Content-Security-Policy']);
            self::assertSame('nosniff', $cabeceras['X-Content-Type-Options']);
            self::assertSame('same-origin', $cabeceras['Referrer-Policy']);
            self::assertSame('DENY', $cabeceras['X-Frame-Options']);
            self::assertSame('no-store', $cabeceras['Cache-Control']);
            self::assertSame('application/json; charset=utf-8', $cabeceras['Content-Type']);
        }
    }

    public function testRespuestaHtmlTambienLlevaLasCabecerasDeSeguridad(): void
    {
        $cabeceras = Respuesta::html('<p>Hola</p>')->cabecerasCompletas();

        self::assertSame("default-src 'self'", $cabeceras['Content-Security-Policy']);
        self::assertSame('text/html; charset=utf-8', $cabeceras['Content-Type']);
    }
}
