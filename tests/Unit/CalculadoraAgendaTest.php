<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\CalculadoraAgenda;
use App\Hueco;
use App\Visita;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Huecos libres del día: tiempo no ocupado por visitas activas ni por el desplazamiento que las precede. */
final class CalculadoraAgendaTest extends TestCase
{
    #[TestDox('Día completo libre: un hueco de 08:00 a 17:00')]
    public function testDiaCompletoLibre(): void
    {
        self::assertSame([['08:00', '17:00', 540]], $this->huecos([]));
    }

    #[TestDox('Ejemplo de la sección 6: visita de 09:00 a 10:30 con 40 min de desplazamiento')]
    public function testEjemploDelAnalisis(): void
    {
        self::assertSame(
            [['08:00', '08:20', 20], ['10:30', '17:00', 390]],
            $this->huecos([$this->visita(1, 540, 90, 40)])
        );
    }

    #[TestDox('El desplazamiento ocupa tiempo antes de cada visita')]
    public function testElDesplazamientoOcupaTiempo(): void
    {
        self::assertSame(
            [['08:00', '09:30', 90], ['11:00', '11:30', 30], ['13:00', '17:00', 240]],
            $this->huecos([$this->visita(1, 600, 60), $this->visita(2, 720, 60, 30)])
        );
    }

    #[TestDox('Visitas encadenadas con el desplazamiento justo no dejan hueco entre ellas')]
    public function testVisitasEncadenadasSinHueco(): void
    {
        self::assertSame(
            [['08:00', '09:30', 90], ['12:30', '17:00', 270]],
            $this->huecos([$this->visita(1, 600, 60), $this->visita(2, 690, 60)])
        );
    }

    #[TestDox('Día completo ocupado: sin huecos')]
    public function testDiaCompletoOcupado(): void
    {
        self::assertSame([], $this->huecos([$this->visita(1, 510, 510)]));
    }

    #[TestDox('Las visitas canceladas no ocupan; las completadas sí')]
    public function testCanceladasNoOcupan(): void
    {
        self::assertSame(
            [['08:00', '09:30', 90], ['11:00', '17:00', 360]],
            $this->huecos([
                $this->visita(1, 600, 60, 30, Visita::COMPLETADA),
                $this->visita(2, 720, 60, 30, Visita::CANCELADA),
            ])
        );
    }

    #[TestDox('El orden de entrada no importa')]
    public function testOrdenDeEntradaIndiferente(): void
    {
        $a = $this->visita(1, 600, 60);
        $b = $this->visita(2, 720, 60);

        self::assertSame($this->huecos([$a, $b]), $this->huecos([$b, $a]));
    }

    #[TestDox('Ocupaciones que se pisan (desplazamiento a revisar) se unen')]
    public function testOcupacionesQueSePisanSeUnen(): void
    {
        // La segunda sale a las 10:45, antes de que acabe la primera: puede pasar tras una cancelación.
        self::assertSame(
            [['08:00', '09:30', 90], ['12:15', '17:00', 285]],
            $this->huecos([$this->visita(1, 600, 60), $this->visita(2, 675, 60)])
        );
    }

    #[TestDox('Lo que queda fuera de la jornada no cuenta')]
    public function testRecortaALaJornada(): void
    {
        // Salida de la sede a las 07:45 (dato marcado para revisar): el hueco no empieza antes de las 08:00.
        self::assertSame([['09:15', '17:00', 465]], $this->huecos([$this->visita(1, 495, 60)]));
    }

    /**
     * @param list<Visita> $visitas
     * @return list<array{string, string, int}>
     */
    private function huecos(array $visitas): array
    {
        return array_map(
            fn (Hueco $h) => [$h->inicioTexto(), $h->finTexto(), $h->minutos()],
            (new CalculadoraAgenda())->huecos($visitas)
        );
    }

    private function visita(int $id, int $inicio, int $duracion, int $desplazamiento = 30, string $estado = Visita::PROGRAMADA): Visita
    {
        return new Visita($id, 1, 1, 'Obra A', '2026-11-03', $inicio, $duracion, $desplazamiento, $estado);
    }
}
