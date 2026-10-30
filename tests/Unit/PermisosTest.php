<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Permisos;
use App\Usuario;
use App\Visita;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Reglas de permisos de los casos 21–28 de la sección 8 (sin HTTP). Ana es la técnica 1 y Bernat el 2. */
final class PermisosTest extends TestCase
{
    private Permisos $permisos;
    private Usuario $planificador;
    private Usuario $ana;
    private Usuario $bernat;

    protected function setUp(): void
    {
        $this->permisos = new Permisos();
        $this->planificador = new Usuario(10, Usuario::PLANIFICADOR, null);
        $this->ana = new Usuario(11, Usuario::TECNICO, 1);
        $this->bernat = new Usuario(12, Usuario::TECNICO, 2);
    }

    #[TestDox('Caso 21: Ana puede completar su propia visita')]
    public function testCaso21AnaPuedeCompletarSuVisita(): void
    {
        self::assertTrue($this->permisos->puedeCompletar($this->ana, $this->visitaDe(1)));
    }

    #[TestDox('Caso 25: Ana no puede completar ni cancelar una visita de Bernat')]
    public function testCaso25AnaNoActuaSobreVisitasDeBernat(): void
    {
        $deBernat = $this->visitaDe(2);

        self::assertFalse($this->permisos->puedeCompletar($this->ana, $deBernat));
        self::assertFalse($this->permisos->puedeCancelar($this->ana, $deBernat));
    }

    #[TestDox('Caso 26: Ana pide las visitas de Bernat y se le fuerzan las suyas')]
    public function testCaso26AlTecnicoSeLeFuerzaSuTecnico(): void
    {
        self::assertSame(1, $this->permisos->tecnicoParaConsulta($this->ana, 2));
        self::assertSame(1, $this->permisos->tecnicoParaConsulta($this->ana, null));
        self::assertSame(2, $this->permisos->tecnicoParaConsulta($this->bernat, 1));
    }

    #[TestDox('Caso 27: Ana no puede programar visitas')]
    public function testCaso27UnTecnicoNoPuedeProgramar(): void
    {
        self::assertFalse($this->permisos->puedeProgramar($this->ana));
    }

    #[TestDox('El planificador puede programar y validar visitas')]
    public function testElPlanificadorPuedeProgramar(): void
    {
        self::assertTrue($this->permisos->puedeProgramar($this->planificador));
    }

    #[TestDox('El planificador consulta el técnico que pide, o todos si no pide ninguno')]
    public function testElPlanificadorConsultaCualquierTecnico(): void
    {
        self::assertSame(2, $this->permisos->tecnicoParaConsulta($this->planificador, 2));
        self::assertNull($this->permisos->tecnicoParaConsulta($this->planificador, null));
    }

    #[TestDox('Agenda: el planificador ve la de cualquiera; el técnico solo la suya')]
    public function testVerAgenda(): void
    {
        self::assertTrue($this->permisos->puedeVerAgenda($this->planificador, 2));
        self::assertTrue($this->permisos->puedeVerAgenda($this->ana, 1));
        self::assertFalse($this->permisos->puedeVerAgenda($this->ana, 2));
    }

    #[TestDox('El planificador no completa visitas, pero puede cancelar cualquiera')]
    public function testPlanificadorCancelaPeroNoCompleta(): void
    {
        self::assertFalse($this->permisos->puedeCompletar($this->planificador, $this->visitaDe(1)));
        self::assertTrue($this->permisos->puedeCancelar($this->planificador, $this->visitaDe(1)));
        self::assertTrue($this->permisos->puedeCancelar($this->planificador, $this->visitaDe(2)));
    }

    #[TestDox('El técnico puede cancelar sus propias visitas')]
    public function testElTecnicoCancelaLasSuyas(): void
    {
        self::assertTrue($this->permisos->puedeCancelar($this->ana, $this->visitaDe(1)));
    }

    #[TestDox('Un usuario con rol desconocido no puede hacer nada (denegar por defecto)')]
    public function testRolDesconocidoNoPuedeNada(): void
    {
        $raro = new Usuario(99, 'ADMIN', 1);

        self::assertFalse($this->permisos->puedeProgramar($raro));
        self::assertFalse($this->permisos->puedeVerAgenda($raro, 1));
        self::assertFalse($this->permisos->puedeCompletar($raro, $this->visitaDe(1)));
        self::assertFalse($this->permisos->puedeCancelar($raro, $this->visitaDe(1)));
        self::assertFalse($this->permisos->puedeConsultarVisitas($raro));
    }

    #[TestDox('Un técnico sin técnico asociado no puede actuar sobre ninguna visita')]
    public function testTecnicoSinTecnicoAsociado(): void
    {
        $huerfano = new Usuario(98, Usuario::TECNICO, null);

        self::assertFalse($this->permisos->puedeVerAgenda($huerfano, 1));
        self::assertFalse($this->permisos->puedeCompletar($huerfano, $this->visitaDe(1)));
        self::assertFalse($this->permisos->puedeCancelar($huerfano, $this->visitaDe(1)));
        self::assertFalse($this->permisos->puedeConsultarVisitas($huerfano));
    }

    #[TestDox('Planificador y técnico con técnico asociado pueden consultar visitas')]
    public function testPuedenConsultarVisitas(): void
    {
        self::assertTrue($this->permisos->puedeConsultarVisitas($this->planificador));
        self::assertTrue($this->permisos->puedeConsultarVisitas($this->ana));
    }

    #[TestDox('Pedir el técnico de consulta sin permiso es un error de programación')]
    public function testTecnicoParaConsultaSinPermisoLanzaExcepcion(): void
    {
        $this->expectException(LogicException::class);
        $this->permisos->tecnicoParaConsulta(new Usuario(98, Usuario::TECNICO, null), 1);
    }

    private function visitaDe(int $tecnicoId): Visita
    {
        return new Visita(7, $tecnicoId, 1, 'Obra A', '2026-11-03', 600, 60, 30);
    }
}
