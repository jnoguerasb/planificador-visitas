<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ErrorRegla;
use App\ErroresDeNegocio;
use App\Horas;
use App\Hueco;
use App\Tests\Support\ServicioTest;
use App\Visita;
use PHPUnit\Framework\Attributes\TestDox;

/** VisitaService::agenda y ::listar contra la base de datos. */
final class ConsultasTest extends ServicioTest
{
    private const VISITAS_DEL_SEED = 12;

    // --- Agenda ---

    #[TestDox('Agenda: visitas del día en orden, con las canceladas, y huecos calculados solo con las activas')]
    public function testAgendaDelDia(): void
    {
        $cancelada = $this->insertarVisita(['hora_inicio' => '12:00', 'obra_id' => 2, 'estado' => 'Cancelada', 'motivo_cancelacion' => 'Obra cerrada']);
        $primera = $this->insertarVisita();

        $agenda = $this->servicio()->agenda($this->ana, 1, self::FECHA);

        self::assertSame([1, 'Ana Puig', self::FECHA], [$agenda->tecnicoId, $agenda->tecnicoNombre, $agenda->fecha]);
        self::assertSame(
            [[$primera, '10:00', self::OBRA_A, Visita::PROGRAMADA], [$cancelada, '12:00', 'Viviendas Rambla', Visita::CANCELADA]],
            array_map(fn (Visita $v) => [$v->id, Horas::aTexto($v->inicio), $v->obraNombre, $v->estado], $agenda->visitas)
        );
        self::assertSame([['08:00', '09:30'], ['11:00', '17:00']], array_map(fn (Hueco $h) => [$h->inicioTexto(), $h->finTexto()], $agenda->huecos));
    }

    #[TestDox('Agenda: el planificador ve la de cualquiera; un técnico solo la suya')]
    public function testPermisosDeLaAgenda(): void
    {
        self::assertSame('Bernat Soler', $this->servicio()->agenda($this->planificador, 2, self::FECHA)->tecnicoNombre);
        self::assertSame('Ana Puig', $this->servicio()->agenda($this->ana, 1, self::FECHA)->tecnicoNombre);
        self::assertSame([ErrorRegla::PERMISO], $this->errores(fn () => $this->servicio()->agenda($this->ana, 2, self::FECHA))->reglas());
    }

    #[TestDox('Agenda: fecha imposible o técnico inexistente dan CAMPOS')]
    public function testAgendaConDatosNoValidos(): void
    {
        self::assertSame([ErrorRegla::CAMPOS], $this->errores(fn () => $this->servicio()->agenda($this->planificador, 1, '2030-02-31'))->reglas());
        self::assertSame([ErrorRegla::CAMPOS], $this->errores(fn () => $this->servicio()->agenda($this->planificador, 99, self::FECHA))->reglas());
        self::assertSame([ErrorRegla::CAMPOS], $this->errores(fn () => $this->servicio()->agenda($this->planificador, 99_999_999_999, self::FECHA))->reglas());
    }

    // --- Listado ---

    #[TestDox('Listado sin filtros: todas las visitas, por fecha, hora y técnico')]
    public function testListadoSinFiltros(): void
    {
        $this->insertarVisita(['tecnico_id' => 2]);
        $this->insertarVisita();

        $filas = $this->servicio()->listar($this->planificador, null, null, null);

        self::assertCount(self::VISITAS_DEL_SEED + 2, $filas);
        $orden = array_map(fn (array $f) => [$f['fecha'], $f['inicio'], $f['tecnico']], $filas);
        $ordenado = $orden;
        sort($ordenado);
        self::assertSame($ordenado, $orden);
        self::assertSame([self::FECHA, '10:00', 'Ana Puig'], $orden[self::VISITAS_DEL_SEED]);
        self::assertSame([self::FECHA, '10:00', 'Bernat Soler'], $orden[self::VISITAS_DEL_SEED + 1]);
    }

    #[TestDox('Listado: los filtros de técnico, fecha y estado se combinan con Y')]
    public function testFiltrosCombinados(): void
    {
        $buscada = $this->insertarVisita();
        $this->insertarVisita(['hora_inicio' => '12:00', 'estado' => 'Cancelada', 'motivo_cancelacion' => 'Obra cerrada']);
        $this->insertarVisita(['tecnico_id' => 2]);
        $this->insertarVisita(['fecha' => '2030-01-16']);

        $filas = $this->servicio()->listar($this->planificador, 1, self::FECHA, Visita::PROGRAMADA);

        self::assertSame([$buscada], array_column($filas, 'id'));
        self::assertSame([
            'id' => $buscada, 'fecha' => self::FECHA, 'inicio' => '10:00', 'fin' => '11:00',
            'tecnico_id' => 1, 'tecnico' => 'Ana Puig', 'obra_id' => 1, 'obra' => self::OBRA_A, 'municipio' => 'Sabadell',
            'desplazamiento_min' => 30, 'origen_tipo' => 'SEDE', 'revisar_desplazamiento' => false, 'estado' => 'Programada',
            'comentario_cierre' => null, 'motivo_cancelacion' => null,
            'completada_por' => null, 'completada_en' => null, 'cancelada_por' => null, 'cancelada_en' => null,
        ], $filas[0]);
    }

    #[TestDox('Caso 26: Ana pide las visitas de Bernat y recibe solo las suyas')]
    public function testCaso26AlTecnicoSeLeFuerzanLasSuyas(): void
    {
        $this->insertarVisita(['tecnico_id' => 2]);

        foreach ([2, null] as $pedido) {
            $filas = $this->servicio()->listar($this->ana, $pedido, null, null);

            self::assertNotSame([], $filas);
            self::assertSame([1], array_values(array_unique(array_column($filas, 'tecnico_id'))));
        }
    }

    #[TestDox('Listado: autor de la cancelación o del cierre (nombre del técnico, o email del planificador)')]
    public function testListadoMuestraElAutor(): void
    {
        $deAna = $this->insertarVisita();
        $delPlanificador = $this->insertarVisita(['hora_inicio' => '12:00']);
        $this->servicio(self::FECHA . ' 10:30')->completar($this->ana, $deAna, 'Revisada la estructura sin incidencias');
        $this->servicio(self::FECHA . ' 10:45')->cancelar($this->planificador, $delPlanificador, 'Obra cerrada');

        $filas = array_column($this->servicio()->listar($this->planificador, 1, self::FECHA, null), null, 'id');

        self::assertSame(['Ana Puig', '2030-01-15 10:30'], [$filas[$deAna]['completada_por'], $filas[$deAna]['completada_en']]);
        self::assertSame(['planificador@test.local', '2030-01-15 10:45', 'Obra cerrada'], [
            $filas[$delPlanificador]['cancelada_por'], $filas[$delPlanificador]['cancelada_en'], $filas[$delPlanificador]['motivo_cancelacion'],
        ]);
    }

    #[TestDox('Listado: estado, fecha o técnico no válidos dan CAMPOS')]
    public function testListadoConFiltrosNoValidos(): void
    {
        $casos = [
            [null, null, 'Borrada'],
            [null, '15/01/2030', null],
            [0, null, null],
            [99_999_999_999, null, null],
        ];
        foreach ($casos as [$tecnico, $fecha, $estado]) {
            $errores = $this->errores(fn () => $this->servicio()->listar($this->planificador, $tecnico, $fecha, $estado));
            self::assertSame([ErrorRegla::CAMPOS], $errores->reglas(), json_encode([$tecnico, $fecha, $estado]));
        }
    }

    private function errores(callable $accion): ErroresDeNegocio
    {
        try {
            $accion();
        } catch (ErroresDeNegocio $e) {
            return $e;
        }
        self::fail('Se esperaban errores de negocio');
    }
}
