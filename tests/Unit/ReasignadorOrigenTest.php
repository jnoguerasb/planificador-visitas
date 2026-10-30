<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ReasignadorOrigen;
use App\Visita;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Recalcular el origen de la visita que dependía de una cancelada (sección 4, "Qué pasa al cancelar"). */
final class ReasignadorOrigenTest extends TestCase
{
    private const FECHA = '2026-11-03';

    #[TestDox('Caso 19: al cancelar la de las 08:30 del caso 18, la de las 10:00 vuelve a salir de la sede')]
    public function testCaso19LaSiguienteVuelveALaSede(): void
    {
        $nueva = $this->visita(2, 2, 'Obra B', 510, 30);                  // 08:30–09:00, desde la sede
        $existente = $this->visita(1, 1, 'Obra A', 600, 60, 50, origen: 2); // 10:00–11:00, desde la Obra B con 50 min

        $cambio = (new ReasignadorOrigen())->trasCancelar($nueva, [$nueva, $existente]);

        self::assertNotNull($cambio);
        self::assertSame(1, $cambio->visitaId);
        self::assertNull($cambio->origenVisitaId);
        self::assertSame(Visita::ORIGEN_SEDE, $cambio->origenTipo());
    }

    #[TestDox('En una cadena de tres, la tercera pasa a salir de la primera')]
    public function testLaSiguientePasaASalirDeLaAnteriorALaCancelada(): void
    {
        $primera = $this->visita(1, 1, 'Obra A', 510, 60);
        $segunda = $this->visita(2, 2, 'Obra B', 660, 60, origen: 1);
        $tercera = $this->visita(3, 3, 'Obra C', 810, 60, origen: 2);

        $cambio = (new ReasignadorOrigen())->trasCancelar($segunda, [$tercera, $primera, $segunda]);

        self::assertNotNull($cambio);
        self::assertSame(3, $cambio->visitaId);
        self::assertSame(1, $cambio->origenVisitaId);
        self::assertSame(Visita::ORIGEN_VISITA, $cambio->origenTipo());
    }

    #[TestDox('Si nadie salía de la cancelada, no hay nada que cambiar')]
    public function testSinVisitaDependienteNoHayCambio(): void
    {
        $unica = $this->visita(1, 1, 'Obra A', 600, 60);

        self::assertNull((new ReasignadorOrigen())->trasCancelar($unica, [$unica]));
    }

    #[TestDox('Las visitas canceladas no cuentan como dependiente ni como nuevo origen')]
    public function testIgnoraLasCanceladas(): void
    {
        $canceladaAntes = $this->visita(1, 1, 'Obra A', 510, 60, estado: Visita::CANCELADA);
        $aCancelar = $this->visita(2, 2, 'Obra B', 660, 60);
        $dependienteCancelada = $this->visita(3, 3, 'Obra C', 810, 60, origen: 2, estado: Visita::CANCELADA);
        $dependiente = $this->visita(4, 3, 'Obra C', 900, 60, origen: 2);

        $cambio = (new ReasignadorOrigen())->trasCancelar($aCancelar, [$canceladaAntes, $aCancelar, $dependienteCancelada, $dependiente]);

        self::assertNotNull($cambio);
        self::assertSame(4, $cambio->visitaId);
        self::assertNull($cambio->origenVisitaId, 'La anterior está cancelada: el nuevo origen es la sede');
    }

    #[TestDox('Una visita Completada anterior sí sirve como nuevo origen')]
    public function testUnaCompletadaSirveDeOrigen(): void
    {
        $completada = $this->visita(1, 1, 'Obra A', 510, 60, estado: Visita::COMPLETADA);
        $aCancelar = $this->visita(2, 2, 'Obra B', 660, 60, origen: 1);
        $dependiente = $this->visita(3, 3, 'Obra C', 810, 60, origen: 2);

        $cambio = (new ReasignadorOrigen())->trasCancelar($aCancelar, [$completada, $aCancelar, $dependiente]);

        self::assertSame(1, $cambio?->origenVisitaId);
    }

    private function visita(
        int $id,
        int $obraId,
        string $obra,
        int $inicio,
        int $duracion,
        int $desplazamiento = 30,
        ?int $origen = null,
        string $estado = Visita::PROGRAMADA,
    ): Visita {
        return new Visita($id, 1, $obraId, $obra, self::FECHA, $inicio, $duracion, $desplazamiento, $estado, $origen);
    }
}
