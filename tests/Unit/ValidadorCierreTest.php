<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ErrorRegla;
use App\Usuario;
use App\ValidadorCierre;
use App\Visita;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Completar y cancelar: casos 21–25 y 28 de la sección 8. Ana (técnica 1) tiene una visita
 * Programada el 03/11 de 10:00 a 11:00.
 */
final class ValidadorCierreTest extends TestCase
{
    private const COMENTARIO_VALIDO = 'Revisada la estructura sin incidencias'; // 38 caracteres

    private ValidadorCierre $validador;
    private Usuario $planificador;
    private Usuario $ana;

    protected function setUp(): void
    {
        $this->validador = new ValidadorCierre();
        $this->planificador = new Usuario(10, Usuario::PLANIFICADOR, null);
        $this->ana = new Usuario(11, Usuario::TECNICO, 1);
    }

    #[TestDox('Caso 21: Ana completa su visita de las 10:00 a las 10:15 con un comentario de 30 caracteres')]
    public function testCaso21CompletarCorrecto(): void
    {
        $comentario = str_repeat('a', 30);

        self::assertSame([], $this->validador->completar($this->ana, $this->visita(), $comentario, $this->momento('2026-11-03 10:15')));
    }

    #[TestDox('Caso 22: Ana intenta completar una visita de mañana y da MOMENTO')]
    public function testCaso22CompletarAntesDeTiempo(): void
    {
        $errores = $this->validador->completar($this->ana, $this->visita(), self::COMENTARIO_VALIDO, $this->momento('2026-11-02 18:00'));

        $this->assertErrores([[ErrorRegla::MOMENTO, 'Podrás completar esta visita a partir del 03/11 a las 10:00']], $errores);
    }

    #[TestDox('Caso 23: Ana completa con un comentario de 4 caracteres y da TEXTO')]
    public function testCaso23ComentarioDemasiadoCorto(): void
    {
        $errores = $this->validador->completar($this->ana, $this->visita(), 'Hecho', $this->momento('2026-11-03 10:15'));

        $this->assertErrores([[ErrorRegla::TEXTO, 'Describe el trabajo realizado (mínimo 10 caracteres)']], $errores);
        self::assertSame('comentario', $errores[0]->campo);
    }

    #[TestDox('Caso 24: Ana cancela sin motivo y da TEXTO')]
    public function testCaso24CancelarSinMotivo(): void
    {
        $errores = $this->validador->cancelar($this->ana, $this->visita(), '');

        $this->assertErrores([[ErrorRegla::TEXTO, 'Indica el motivo de la cancelación (mínimo 5 caracteres)']], $errores);
        self::assertSame('motivo', $errores[0]->campo);
    }

    #[TestDox('Caso 25: Ana completa o cancela una visita de Bernat y da PERMISO')]
    public function testCaso25VisitaDeOtroTecnico(): void
    {
        $deBernat = $this->visita(Visita::PROGRAMADA, 2);
        $permiso = [[ErrorRegla::PERMISO, 'No tienes permiso para modificar esta visita']];

        $this->assertErrores($permiso, $this->validador->completar($this->ana, $deBernat, self::COMENTARIO_VALIDO, $this->momento('2026-11-03 10:15')));
        $this->assertErrores($permiso, $this->validador->cancelar($this->ana, $deBernat, 'Obra cerrada'));
    }

    #[TestDox('Caso 28: completar una Cancelada o cancelar una Completada da ESTADO')]
    public function testCaso28TransicionesNoPermitidas(): void
    {
        $this->assertErrores(
            [[ErrorRegla::ESTADO, 'La visita ya está Cancelada']],
            $this->validador->completar($this->ana, $this->visita(Visita::CANCELADA), self::COMENTARIO_VALIDO, $this->momento('2026-11-03 10:15'))
        );
        $this->assertErrores(
            [[ErrorRegla::ESTADO, 'La visita ya está Completada']],
            $this->validador->cancelar($this->ana, $this->visita(Visita::COMPLETADA), 'Obra cerrada')
        );
        $this->assertErrores(
            [[ErrorRegla::ESTADO, 'La visita ya está Completada']],
            $this->validador->completar($this->ana, $this->visita(Visita::COMPLETADA), self::COMENTARIO_VALIDO, $this->momento('2026-11-03 10:15'))
        );
    }

    #[TestDox('PERMISO va antes que ESTADO: no se revela el estado de visitas ajenas')]
    public function testPermisoAntesQueEstado(): void
    {
        $errores = $this->validador->cancelar($this->ana, $this->visita(Visita::COMPLETADA, 2), '');

        self::assertSame([ErrorRegla::PERMISO], array_map(fn (ErrorRegla $e) => $e->regla, $errores));
    }

    #[TestDox('ESTADO va solo: no se informa además de texto ni de momento')]
    public function testEstadoVaSolo(): void
    {
        $errores = $this->validador->completar($this->ana, $this->visita(Visita::CANCELADA), '', $this->momento('2026-11-01 08:00'));

        self::assertSame([ErrorRegla::ESTADO], array_map(fn (ErrorRegla $e) => $e->regla, $errores));
    }

    #[TestDox('Completar antes de tiempo y con texto corto devuelve los dos errores')]
    public function testMomentoYTextoALaVez(): void
    {
        $errores = $this->validador->completar($this->ana, $this->visita(), 'corto', $this->momento('2026-11-03 09:59'));

        self::assertSame([ErrorRegla::MOMENTO, ErrorRegla::TEXTO], array_map(fn (ErrorRegla $e) => $e->regla, $errores));
    }

    #[TestDox('Límite: se puede completar justo a la hora de inicio, no un minuto antes')]
    public function testCompletarJustoALaHoraDeInicio(): void
    {
        self::assertSame([], $this->validador->completar($this->ana, $this->visita(), self::COMENTARIO_VALIDO, $this->momento('2026-11-03 10:00')));
        self::assertNotSame([], $this->validador->completar($this->ana, $this->visita(), self::COMENTARIO_VALIDO, $this->momento('2026-11-03 09:59')));
    }

    #[TestDox('El planificador no puede completar visitas')]
    public function testElPlanificadorNoCompleta(): void
    {
        $errores = $this->validador->completar($this->planificador, $this->visita(), self::COMENTARIO_VALIDO, $this->momento('2026-11-03 10:15'));

        self::assertSame([ErrorRegla::PERMISO], array_map(fn (ErrorRegla $e) => $e->regla, $errores));
    }

    #[TestDox('El planificador puede cancelar con motivo')]
    public function testElPlanificadorCancela(): void
    {
        self::assertSame([], $this->validador->cancelar($this->planificador, $this->visita(Visita::PROGRAMADA, 2), 'Obra cerrada'));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function comentarios(): array
    {
        return [
            '10 caracteres' => ['abcdefghij', true],
            '10 con acentos' => ['áéíóúñçàèò', true],
            '9 caracteres' => ['abcdefghi', false],
            'espacios alrededor no cuentan' => ['   abcdefghi   ', false],
            'solo espacios' => [str_repeat(' ', 20), false],
            '2000 caracteres' => [str_repeat('é', 2000), true],
            'nulo' => [null, false],
            'número' => [1234567890, false],
        ];
    }

    #[DataProvider('comentarios')]
    public function testLongitudDelComentario(mixed $comentario, bool $valido): void
    {
        $errores = $this->validador->completar($this->ana, $this->visita(), $comentario, $this->momento('2026-11-03 10:15'));

        self::assertSame($valido, $errores === []);
    }

    public function testComentarioDeMasDe2000CaracteresDaTexto(): void
    {
        $errores = $this->validador->completar($this->ana, $this->visita(), str_repeat('a', 2001), $this->momento('2026-11-03 10:15'));

        $this->assertErrores([[ErrorRegla::TEXTO, 'El comentario no puede superar 2000 caracteres']], $errores);
    }

    /** @return array<string, array{mixed, bool}> */
    public static function motivos(): array
    {
        return [
            '5 caracteres' => ['Nieve', true],
            '5 con acentos (multibyte)' => ['Pañol', true],
            '4 caracteres' => ['Baja', false],
            'solo espacios' => ['          ', false],
            'nulo' => [null, false],
            '2000 caracteres' => [str_repeat('x', 2000), true],
        ];
    }

    #[DataProvider('motivos')]
    public function testLongitudDelMotivo(mixed $motivo, bool $valido): void
    {
        self::assertSame($valido, $this->validador->cancelar($this->ana, $this->visita(), $motivo) === []);
    }

    public function testMotivoDeMasDe2000CaracteresDaTexto(): void
    {
        $errores = $this->validador->cancelar($this->ana, $this->visita(), str_repeat('a', 2001));

        $this->assertErrores([[ErrorRegla::TEXTO, 'El motivo no puede superar 2000 caracteres']], $errores);
    }

    private function visita(string $estado = Visita::PROGRAMADA, int $tecnicoId = 1): Visita
    {
        return new Visita(7, $tecnicoId, 1, 'Obra A', '2026-11-03', 600, 60, 30, $estado);
    }

    private function momento(string $texto): DateTimeImmutable
    {
        return new DateTimeImmutable($texto, new DateTimeZone('Europe/Madrid'));
    }

    /**
     * @param list<array{string, string}> $esperados
     * @param list<ErrorRegla> $errores
     */
    private function assertErrores(array $esperados, array $errores): void
    {
        self::assertSame($esperados, array_map(fn (ErrorRegla $e) => [$e->regla, $e->mensaje], $errores));
    }
}
