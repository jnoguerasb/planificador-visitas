<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ErrorRegla;
use App\ErroresDeNegocio;
use App\Tests\Support\ServicioTest;
use App\Visita;
use PDOException;
use PHPUnit\Framework\Attributes\TestDox;

/** VisitaService::programar y ::validar contra la base de datos. */
final class ProgramarVisitaTest extends ServicioTest
{
    #[TestDox('Caso 18 de punta a punta: se guarda la de las 08:30 y la de las 10:00 pasa a salir de ella con 50 min')]
    public function testCaso18DePuntaAPunta(): void
    {
        $existente = $this->insertarVisita();

        $id = $this->servicio()->programar($this->planificador, $this->datos('08:30', 30, ['desplazamiento_siguiente_min' => 50]));

        $nueva = $this->fila($id);
        self::assertSame(['08:30', 30, 30, 'SEDE', null, 'Programada', 1, '2030-01-15 12:00'], [
            $nueva['inicio'], $nueva['duracion_min'], $nueva['desplazamiento_min'], $nueva['origen_tipo'],
            $nueva['origen_visita_id'], $nueva['estado'], $nueva['creada_por'], $nueva['creada'],
        ]);

        $siguiente = $this->fila($existente);
        self::assertSame(['VISITA', $id, 50, false], [
            $siguiente['origen_tipo'], $siguiente['origen_visita_id'], $siguiente['desplazamiento_min'], $siguiente['revisar_desplazamiento'],
        ]);
    }

    #[TestDox('Visita intercalada: sale de la anterior y la siguiente pasa a salir de ella conservando su desplazamiento')]
    public function testVisitaIntercaladaEntreDos(): void
    {
        $primera = $this->insertarVisita();
        $ultima = $this->insertarVisita(['hora_inicio' => '14:00', 'obra_id' => 3, 'desplazamiento_min' => 45,
            'origen_tipo' => 'VISITA', 'origen_visita_id' => $primera]);

        $id = $this->servicio()->programar($this->planificador, $this->datos('11:30', 60));

        $nueva = $this->fila($id);
        self::assertSame(['VISITA', $primera], [$nueva['origen_tipo'], $nueva['origen_visita_id']]);
        $siguiente = $this->fila($ultima);
        self::assertSame(['VISITA', $id, 45], [$siguiente['origen_tipo'], $siguiente['origen_visita_id'], $siguiente['desplazamiento_min']]);
    }

    #[TestDox('Al intercalar una visita, la siguiente deja de estar marcada "Revisar desplazamiento"')]
    public function testIntercalarQuitaRevisarDesplazamiento(): void
    {
        $siguiente = $this->insertarVisita(['hora_inicio' => '14:00', 'revisar_desplazamiento' => 'true']);

        $this->servicio()->programar($this->planificador, $this->datos('11:00', 60, ['desplazamiento_siguiente_min' => 60]));

        $fila = $this->fila($siguiente);
        self::assertFalse($fila['revisar_desplazamiento']);
        self::assertSame(60, $fila['desplazamiento_min']);
    }

    #[TestDox('R1 en base de datos: dos visitas seguidas en la misma obra; la siguiente pasa a salir de la nueva con 0 min')]
    public function testMismaObraEncadenada(): void
    {
        $existente = $this->insertarVisita(); // 10:00–11:00 en la obra 1, 30 min desde la sede

        $id = $this->servicio()->programar($this->planificador, $this->datos('09:00', 60, ['obra_id' => 1, 'desplazamiento_min' => 0]));

        self::assertSame(['SEDE', 0], [$this->fila($id)['origen_tipo'], $this->fila($id)['desplazamiento_min']]);
        $siguiente = $this->fila($existente);
        self::assertSame(['VISITA', $id, 0], [$siguiente['origen_tipo'], $siguiente['origen_visita_id'], $siguiente['desplazamiento_min']]);
    }

    #[TestDox('R2 en base de datos: la primera visita del día puede empezar a las 08:00 sin desplazamiento')]
    public function testPrimeraVisitaALas08(): void
    {
        $id = $this->servicio()->programar($this->planificador, $this->datos('08:00', 540, ['desplazamiento_min' => 0]));

        self::assertSame(['08:00', 540, 0], [$this->fila($id)['inicio'], $this->fila($id)['duracion_min'], $this->fila($id)['desplazamiento_min']]);
    }

    #[TestDox('Caso 20 en base de datos: el origen que envía el navegador se ignora')]
    public function testCaso20ElOrigenDelClienteSeIgnora(): void
    {
        $anterior = $this->insertarVisita();

        $id = $this->servicio()->programar($this->planificador, $this->datos('12:00', 60, ['origen_tipo' => 'SEDE', 'origen_visita_id' => null]));

        self::assertSame(['VISITA', $anterior], [$this->fila($id)['origen_tipo'], $this->fila($id)['origen_visita_id']]);
    }

    #[TestDox('Un error de validación no guarda nada: ni la nueva ni el cambio de la siguiente')]
    public function testErrorDeValidacionNoGuardaNada(): void
    {
        $existente = $this->insertarVisita();
        $antes = $this->numeroDeVisitas();

        $errores = $this->erroresAlProgramar($this->datos('08:30', 45, ['desplazamiento_siguiente_min' => 50])); // caso 17

        self::assertSame([ErrorRegla::DESPLAZAMIENTO], $errores->reglas());
        self::assertSame($antes, $this->numeroDeVisitas());
        $fila = $this->fila($existente);
        self::assertSame(['SEDE', null, 30], [$fila['origen_tipo'], $fila['origen_visita_id'], $fila['desplazamiento_min']]);
    }

    #[TestDox('Un fallo al actualizar la siguiente deshace también la inserción de la nueva (rollback completo)')]
    public function testFalloDespuesDeInsertarHaceRollbackCompleto(): void
    {
        $existente = $this->insertarVisita();
        $antes = $this->numeroDeVisitas();
        // Fallo forzado en el UPDATE de la siguiente, después del INSERT de la nueva.
        $this->admin()->exec(<<<'SQL'
            CREATE FUNCTION fallo_forzado() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'fallo forzado en el test'; END $$;
            CREATE TRIGGER fallo_forzado BEFORE UPDATE ON visitas FOR EACH ROW EXECUTE FUNCTION fallo_forzado();
            SQL);

        try {
            $this->servicio()->programar($this->planificador, $this->datos('08:30', 30, ['desplazamiento_siguiente_min' => 50]));
            self::fail('Se esperaba el fallo forzado');
        } catch (PDOException $e) {
            self::assertStringContainsString('fallo forzado en el test', $e->getMessage());
        }

        self::assertFalse($this->app()->inTransaction(), 'La transacción no debe quedar abierta');
        self::assertSame($antes, $this->numeroDeVisitas());
        self::assertSame(30, $this->fila($existente)['desplazamiento_min']);
    }

    #[TestDox('Caso 14 en base de datos: desplazamiento 20 da CAMPOS y no guarda nada')]
    public function testCaso14DesplazamientoMenorDe30(): void
    {
        // Partida común: viene de la Obra A (10:00–11:00). Entre obras diferentes el mínimo es 30.
        $this->insertarVisita();
        $antes = $this->numeroDeVisitas();

        $errores = $this->erroresAlProgramar($this->datos('11:30', 60, ['desplazamiento_min' => 20]));

        self::assertSame([[ErrorRegla::CAMPOS, 'El desplazamiento mínimo es 30 min']], $this->reglasYMensajes($errores));
        self::assertSame($antes, $this->numeroDeVisitas());
    }

    #[TestDox('Caso 27: un técnico no puede programar')]
    public function testCaso27UnTecnicoNoPuedeProgramar(): void
    {
        $antes = $this->numeroDeVisitas();

        try {
            $this->servicio()->programar($this->ana, $this->datos('12:00', 60));
            self::fail('Se esperaba PERMISO');
        } catch (ErroresDeNegocio $e) {
            self::assertSame([ErrorRegla::PERMISO], $e->reglas());
        }
        self::assertSame($antes, $this->numeroDeVisitas());
    }

    #[TestDox('Técnico inexistente, fuera de rango o inactivo, y fecha imposible dan CAMPOS sin error de base de datos')]
    public function testDatosQueNoExistenDanCampos(): void
    {
        $this->admin()->exec('UPDATE tecnicos SET activo = FALSE WHERE id = 4');

        foreach ([['tecnico_id' => 99], ['tecnico_id' => 99_999_999_999], ['tecnico_id' => 4], ['obra_id' => 99_999_999_999]] as $cambio) {
            self::assertSame([ErrorRegla::CAMPOS], $this->erroresAlProgramar($this->datos('12:00', 60, $cambio))->reglas(), json_encode($cambio));
        }
        self::assertSame([ErrorRegla::CAMPOS], $this->erroresAlProgramar($this->datos('12:00', 60, ['fecha' => '2030-02-31']))->reglas());
    }

    #[TestDox('Los mensajes usan el nombre real de la obra')]
    public function testLosMensajesUsanElNombreDeLaObra(): void
    {
        $this->insertarVisita();

        $errores = $this->erroresAlProgramar($this->datos('10:30', 60));

        self::assertSame([[ErrorRegla::SOLAPE, 'Solapa con la visita de 10:00 a 11:00 en ' . self::OBRA_A]], $this->reglasYMensajes($errores));
    }

    #[TestDox('Las visitas de otro técnico, de otro día o canceladas no impiden programar')]
    public function testSoloCuentanLasActivasDelTecnicoYDia(): void
    {
        $this->insertarVisita(['tecnico_id' => 2]);
        $this->insertarVisita(['fecha' => '2030-01-16']);
        $this->insertarVisita(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Obra cerrada']);

        $id = $this->servicio()->programar($this->planificador, $this->datos('10:00', 60));

        self::assertSame('SEDE', $this->fila($id)['origen_tipo']);
    }

    #[TestDox('validar devuelve el resultado y el origen calculado sin dejar filas nuevas ni cambiadas')]
    public function testValidarNoGuardaNada(): void
    {
        $existente = $this->insertarVisita();
        $antes = $this->numeroDeVisitas();

        $resultado = $this->servicio()->validar($this->planificador, $this->datos('08:30', 30, ['desplazamiento_siguiente_min' => 50]));

        self::assertTrue($resultado->esValida());
        self::assertSame(Visita::ORIGEN_SEDE, $resultado->origenTipo());
        self::assertSame($existente, $resultado->siguiente?->id);
        self::assertSame(50, $resultado->desplazamientoSiguiente);
        self::assertSame($antes, $this->numeroDeVisitas());
        self::assertSame(30, $this->fila($existente)['desplazamiento_min']);
        self::assertFalse($this->app()->inTransaction());
    }

    #[TestDox('validar devuelve los mismos errores que programar')]
    public function testValidarYProgramarCoinciden(): void
    {
        $this->insertarVisita();
        $datos = $this->datos('10:30', 420); // caso 9: SOLAPE + JORNADA

        $resultado = $this->servicio()->validar($this->planificador, $datos);
        $errores = $this->erroresAlProgramar($datos);

        self::assertSame([ErrorRegla::JORNADA, ErrorRegla::SOLAPE], $resultado->reglas());
        self::assertEquals($resultado->errores, $errores->errores);
    }

    #[TestDox('Un técnico no puede validar')]
    public function testUnTecnicoNoPuedeValidar(): void
    {
        $this->expectException(ErroresDeNegocio::class);
        $this->servicio()->validar($this->ana, $this->datos('12:00', 60));
    }

    /** @param array<string, mixed> $datos */
    private function erroresAlProgramar(array $datos): ErroresDeNegocio
    {
        try {
            $this->servicio()->programar($this->planificador, $datos);
        } catch (ErroresDeNegocio $e) {
            self::assertFalse($this->app()->inTransaction(), 'La transacción no debe quedar abierta');

            return $e;
        }
        self::fail('Se esperaban errores de negocio');
    }

    /** @return list<array{string, string}> */
    private function reglasYMensajes(ErroresDeNegocio $e): array
    {
        return array_map(fn (ErrorRegla $error) => [$error->regla, $error->mensaje], $e->errores);
    }
}
