<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTest;
use PHPUnit\Framework\Attributes\TestDox;

/** GET /api/visitas y GET /api/agenda. */
final class ConsultasApiTest extends ApiTest
{
    // --- Listado ---

    #[TestDox('GET /api/visitas: el planificador filtra por técnico, fecha y estado')]
    public function testListadoConFiltros(): void
    {
        $buscada = $this->insertarVisita(['fecha' => '2030-03-01']);
        $this->insertarVisita(['fecha' => '2030-03-01', 'tecnico_id' => 2]);
        $this->insertarVisita(['fecha' => '2030-03-01', 'hora_inicio' => '13:00', 'estado' => 'Cancelada', 'motivo_cancelacion' => 'Obra cerrada']);
        $this->entrar('planificador@test.local');

        $respuesta = $this->peticion('GET', '/api/visitas?tecnico_id=1&fecha=2030-03-01&estado=Programada');

        self::assertSame(200, $respuesta['estado'], $respuesta['cuerpo']);
        self::assertSame([$buscada], array_column($respuesta['json']['visitas'], 'id'));
        $fila = $respuesta['json']['visitas'][0];
        self::assertSame(['2030-03-01', '10:00', '11:00', 'Ana Puig', 'Escuela Can Puiggener', 'Programada'], [
            $fila['fecha'], $fila['inicio'], $fila['fin'], $fila['tecnico'], $fila['obra'], $fila['estado'],
        ]);
    }

    #[TestDox('GET /api/visitas sin filtros: todas, por fecha y hora')]
    public function testListadoSinFiltros(): void
    {
        $this->entrar('planificador@test.local');

        $visitas = $this->peticion('GET', '/api/visitas')['json']['visitas'];

        self::assertGreaterThanOrEqual(12, count($visitas));
        $orden = array_map(fn (array $v) => $v['fecha'] . ' ' . $v['inicio'], $visitas);
        $ordenado = $orden;
        sort($ordenado);
        self::assertSame($ordenado, $orden);
    }

    #[TestDox('Caso 26: Ana pide GET /api/visitas?tecnico_id=2 y recibe solo las suyas')]
    public function testCaso26ElTecnicoSoloVeLasSuyas(): void
    {
        $this->entrar('ana@test.local');

        foreach (['/api/visitas?tecnico_id=2', '/api/visitas'] as $ruta) {
            $visitas = $this->peticion('GET', $ruta)['json']['visitas'];

            self::assertNotSame([], $visitas, $ruta);
            self::assertSame([1], array_values(array_unique(array_column($visitas, 'tecnico_id'))), $ruta);
        }
    }

    #[TestDox('GET /api/visitas sin sesión: 401; con filtros no válidos: 422')]
    public function testListadoProtegidoYValidado(): void
    {
        self::assertSame(401, $this->peticion('GET', '/api/visitas')['estado']);

        $this->entrar('planificador@test.local');
        foreach (['?estado=Borrada', '?fecha=01/03/2030', '?tecnico_id=uno', '?tecnico_id=0', '?tecnico_id[]=1', '?pagina=2'] as $consulta) {
            $respuesta = $this->peticion('GET', '/api/visitas' . $consulta);

            self::assertSame(422, $respuesta['estado'], $consulta);
            self::assertSame('CAMPOS', $respuesta['json']['errores'][0]['regla'], $consulta);
        }
    }

    // --- Agenda ---

    #[TestDox('GET /api/agenda: visitas con origen en texto, huecos y jornada')]
    public function testAgenda(): void
    {
        $primera = $this->insertarVisita(['fecha' => '2030-03-02', 'tecnico_id' => 2, 'hora_inicio' => '09:00', 'duracion_min' => 90, 'desplazamiento_min' => 40]);
        $segunda = $this->insertarVisita(['fecha' => '2030-03-02', 'tecnico_id' => 2, 'hora_inicio' => '11:30', 'obra_id' => 3,
            'origen_tipo' => 'VISITA', 'origen_visita_id' => $primera]);
        $this->entrar('planificador@test.local');

        $respuesta = $this->peticion('GET', '/api/agenda?tecnico_id=2&fecha=2030-03-02');

        self::assertSame(200, $respuesta['estado'], $respuesta['cuerpo']);
        $agenda = $respuesta['json'];
        self::assertSame(['id' => 2, 'nombre' => 'Bernat Soler'], $agenda['tecnico']);
        self::assertSame(['2030-03-02', ['inicio' => '08:00', 'fin' => '17:00']], [$agenda['fecha'], $agenda['jornada']]);
        self::assertSame([
            ['inicio' => '08:00', 'fin' => '08:20', 'minutos' => 20],
            ['inicio' => '10:30', 'fin' => '11:00', 'minutos' => 30],
            ['inicio' => '12:30', 'fin' => '17:00', 'minutos' => 270],
        ], $agenda['huecos']);

        self::assertSame([$primera, $segunda], array_column($agenda['visitas'], 'id'));
        self::assertSame([
            'id' => $primera, 'obra_id' => 1, 'obra' => 'Escuela Can Puiggener', 'direccion' => 'Carrer de Josep Aparici, 12',
            'municipio' => 'Sabadell', 'inicio' => '09:00', 'fin' => '10:30', 'salida' => '08:20', 'desplazamiento_min' => 40,
            'origen_tipo' => 'SEDE', 'origen_visita_id' => null, 'origen' => 'Desde la sede',
            'estado' => 'Programada', 'revisar_desplazamiento' => false,
            'acciones' => ['completar' => false, 'cancelar' => true],
        ], $agenda['visitas'][0]);
        self::assertSame('Desde Escuela Can Puiggener (acaba 10:30)', $agenda['visitas'][1]['origen']);
    }

    #[TestDox('GET /api/agenda: el técnico ve la suya sin indicar técnico; la de otro da 403')]
    public function testAgendaDelTecnico(): void
    {
        $this->entrar('ana@test.local');

        $propia = $this->peticion('GET', '/api/agenda?fecha=2030-03-03');
        self::assertSame(200, $propia['estado'], $propia['cuerpo']);
        self::assertSame(['id' => 1, 'nombre' => 'Ana Puig'], $propia['json']['tecnico']);
        self::assertSame(200, $this->peticion('GET', '/api/agenda?tecnico_id=1&fecha=2030-03-03')['estado']);

        $ajena = $this->peticion('GET', '/api/agenda?tecnico_id=2&fecha=2030-03-03');
        self::assertSame(403, $ajena['estado']);
        self::assertSame('PERMISO', $ajena['json']['errores'][0]['regla']);
    }

    #[TestDox('GET /api/agenda: botones según rol, estado y hora (el cliente no decide)')]
    public function testAccionesDeLaAgenda(): void
    {
        $ayer = self::diaRelativo(-1);
        $pasada = $this->insertarVisita(['fecha' => $ayer, 'hora_inicio' => '09:00']);
        $cancelada = $this->insertarVisita(['fecha' => $ayer, 'hora_inicio' => '12:00', 'estado' => 'Cancelada', 'motivo_cancelacion' => 'Obra cerrada']);
        $manana = $this->insertarVisita(['fecha' => self::diaRelativo(1), 'hora_inicio' => '09:00']);

        $this->entrar('ana@test.local');
        $acciones = $this->accionesPorId($this->peticion('GET', "/api/agenda?fecha={$ayer}")['json']);
        self::assertSame(['completar' => true, 'cancelar' => true], $acciones[$pasada]);
        self::assertSame(['completar' => false, 'cancelar' => false], $acciones[$cancelada]);
        $acciones = $this->accionesPorId($this->peticion('GET', '/api/agenda?fecha=' . self::diaRelativo(1))['json']);
        self::assertSame(['completar' => false, 'cancelar' => true], $acciones[$manana]);

        $this->cookies = [];
        $this->entrar('planificador@test.local');
        $acciones = $this->accionesPorId($this->peticion('GET', "/api/agenda?tecnico_id=1&fecha={$ayer}")['json']);
        self::assertSame(['completar' => false, 'cancelar' => true], $acciones[$pasada], 'El planificador no completa');
    }

    #[TestDox('GET /api/agenda sin sesión: 401; sin técnico (planificador), sin fecha o con fecha imposible: 422')]
    public function testAgendaProtegidaYValidada(): void
    {
        self::assertSame(401, $this->peticion('GET', '/api/agenda?tecnico_id=1&fecha=2030-03-03')['estado']);

        $this->entrar('planificador@test.local');
        foreach (['?fecha=2030-03-03', '?tecnico_id=1', '?tecnico_id=1&fecha=2030-02-31', '?tecnico_id=99&fecha=2030-03-03', '?tecnico_id=1&fecha=2030-03-03&x=1'] as $consulta) {
            $respuesta = $this->peticion('GET', '/api/agenda' . $consulta);

            self::assertSame(422, $respuesta['estado'], $consulta);
            self::assertSame('CAMPOS', $respuesta['json']['errores'][0]['regla'], $consulta);
        }
    }

    /**
     * @param array{visitas: list<array{id: int, acciones: array<string, bool>}>} $agenda
     * @return array<int, array<string, bool>>
     */
    private function accionesPorId(array $agenda): array
    {
        return array_column($agenda['visitas'], 'acciones', 'id');
    }
}
