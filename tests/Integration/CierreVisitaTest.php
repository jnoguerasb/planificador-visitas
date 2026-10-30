<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ErrorRegla;
use App\ErroresDeNegocio;
use App\Tests\Support\ServicioTest;
use App\VisitaNoEncontrada;
use PHPUnit\Framework\Attributes\TestDox;

/** VisitaService::completar y ::cancelar contra la base de datos. */
final class CierreVisitaTest extends ServicioTest
{
    private const COMENTARIO = 'Revisada la estructura sin incidencias';

    #[TestDox('Caso 21: Ana completa su visita a las 10:15 y se guardan comentario, autor y hora')]
    public function testCaso21CompletarGuardaAutorYHora(): void
    {
        $id = $this->insertarVisita();

        $this->servicio(self::FECHA . ' 10:15')->completar($this->ana, $id, self::COMENTARIO);

        $fila = $this->fila($id);
        self::assertSame(['Completada', self::COMENTARIO, 2, '2030-01-15 10:15'], [
            $fila['estado'], $fila['comentario_cierre'], $fila['completada_por'], $fila['completada'],
        ]);
    }

    #[TestDox('El comentario se guarda exactamente como llega')]
    public function testElComentarioNoSeModifica(): void
    {
        $id = $this->insertarVisita();
        $comentario = "  Línea 1\n<script>alert(1)</script>  ";

        $this->servicio(self::FECHA . ' 10:15')->completar($this->ana, $id, $comentario);

        self::assertSame($comentario, $this->fila($id)['comentario_cierre']);
    }

    #[TestDox('Caso 22: completar antes de la hora de inicio da MOMENTO y no cambia nada')]
    public function testCaso22CompletarAntesDeTiempo(): void
    {
        $id = $this->insertarVisita();

        $errores = $this->errores(fn () => $this->servicio(self::FECHA . ' 09:59')->completar($this->ana, $id, self::COMENTARIO));

        self::assertSame([[ErrorRegla::MOMENTO, 'Podrás completar esta visita a partir del 15/01 a las 10:00']], $this->reglasYMensajes($errores));
        self::assertSame('Programada', $this->fila($id)['estado']);
    }

    #[TestDox('Caso 23: completar con un comentario de 4 caracteres da TEXTO')]
    public function testCaso23ComentarioCorto(): void
    {
        $id = $this->insertarVisita();

        $errores = $this->errores(fn () => $this->servicio(self::FECHA . ' 10:15')->completar($this->ana, $id, 'Hecho'));

        self::assertSame([ErrorRegla::TEXTO], $errores->reglas());
        self::assertSame('Programada', $this->fila($id)['estado']);
    }

    #[TestDox('Caso 24: cancelar sin motivo da TEXTO')]
    public function testCaso24CancelarSinMotivo(): void
    {
        $id = $this->insertarVisita();

        $errores = $this->errores(fn () => $this->servicio()->cancelar($this->ana, $id, ''));

        self::assertSame([ErrorRegla::TEXTO], $errores->reglas());
        self::assertSame('Programada', $this->fila($id)['estado']);
    }

    #[TestDox('Caso 25: Bernat no puede completar ni cancelar una visita de Ana')]
    public function testCaso25VisitaAjena(): void
    {
        $id = $this->insertarVisita();

        self::assertSame([ErrorRegla::PERMISO], $this->errores(fn () => $this->servicio(self::FECHA . ' 10:15')->completar($this->bernat, $id, self::COMENTARIO))->reglas());
        self::assertSame([ErrorRegla::PERMISO], $this->errores(fn () => $this->servicio()->cancelar($this->bernat, $id, 'Obra cerrada'))->reglas());
        self::assertSame('Programada', $this->fila($id)['estado']);
    }

    #[TestDox('Caso 28: completar una Cancelada o cancelar una Completada da ESTADO')]
    public function testCaso28TransicionesNoPermitidas(): void
    {
        $cancelada = $this->insertarVisita(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Obra cerrada']);
        $completada = $this->insertarVisita(['hora_inicio' => '12:00', 'estado' => 'Completada', 'comentario_cierre' => self::COMENTARIO]);

        self::assertSame([ErrorRegla::ESTADO], $this->errores(fn () => $this->servicio(self::FECHA . ' 13:00')->completar($this->ana, $cancelada, self::COMENTARIO))->reglas());
        self::assertSame([ErrorRegla::ESTADO], $this->errores(fn () => $this->servicio()->cancelar($this->ana, $completada, 'Obra cerrada'))->reglas());
        self::assertSame('Cancelada', $this->fila($cancelada)['estado']);
        self::assertSame('Completada', $this->fila($completada)['estado']);
    }

    #[TestDox('El planificador cancela con motivo y se guardan autor y hora')]
    public function testElPlanificadorCancela(): void
    {
        $id = $this->insertarVisita();

        $this->servicio(self::FECHA . ' 08:05')->cancelar($this->planificador, $id, 'La obra está cerrada');

        $fila = $this->fila($id);
        self::assertSame(['Cancelada', 'La obra está cerrada', 1, '2030-01-15 08:05'], [
            $fila['estado'], $fila['motivo_cancelacion'], $fila['cancelada_por'], $fila['cancelada'],
        ]);
    }

    #[TestDox('Caso 19 de punta a punta: al cancelar la de las 08:30, la de las 10:00 vuelve a la sede y queda para revisar')]
    public function testCaso19DePuntaAPunta(): void
    {
        $existente = $this->insertarVisita();
        $nueva = $this->servicio()->programar($this->planificador, $this->datos('08:30', 30, ['desplazamiento_siguiente_min' => 50]));

        $this->servicio()->cancelar($this->ana, $nueva, 'Cambio de planificación');

        self::assertSame('Cancelada', $this->fila($nueva)['estado']);
        $fila = $this->fila($existente);
        self::assertSame(['SEDE', null, 50, true], [
            $fila['origen_tipo'], $fila['origen_visita_id'], $fila['desplazamiento_min'], $fila['revisar_desplazamiento'],
        ]);
    }

    #[TestDox('Cancelar la del medio de una cadena: la siguiente pasa a salir de la primera y queda para revisar')]
    public function testCancelarLaDelMedio(): void
    {
        $primera = $this->insertarVisita();
        $segunda = $this->insertarVisita(['hora_inicio' => '12:00', 'obra_id' => 2, 'origen_tipo' => 'VISITA', 'origen_visita_id' => $primera]);
        $tercera = $this->insertarVisita(['hora_inicio' => '14:00', 'obra_id' => 3, 'origen_tipo' => 'VISITA', 'origen_visita_id' => $segunda]);

        $this->servicio()->cancelar($this->planificador, $segunda, 'Obra cerrada');

        $fila = $this->fila($tercera);
        self::assertSame(['VISITA', $primera, true], [$fila['origen_tipo'], $fila['origen_visita_id'], $fila['revisar_desplazamiento']]);
        self::assertFalse($this->fila($primera)['revisar_desplazamiento']);
    }

    #[TestDox('Cancelar nunca se bloquea por el desplazamiento de la siguiente, aunque desde la sede no diera tiempo')]
    public function testCancelarNoSeBloquea(): void
    {
        $primera = $this->insertarVisita(['hora_inicio' => '08:30']);
        // Desde la sede, con 120 min, la segunda tendría que salir a las 07:30: no sería válida al programar.
        $segunda = $this->insertarVisita(['hora_inicio' => '09:30', 'obra_id' => 2, 'desplazamiento_min' => 120,
            'origen_tipo' => 'VISITA', 'origen_visita_id' => $primera]);

        $this->servicio()->cancelar($this->ana, $primera, 'Obra cerrada');

        $fila = $this->fila($segunda);
        self::assertSame(['SEDE', true, 120], [$fila['origen_tipo'], $fila['revisar_desplazamiento'], $fila['desplazamiento_min']]);
    }

    #[TestDox('Completar o cancelar una visita que no existe lanza VisitaNoEncontrada')]
    public function testVisitaInexistente(): void
    {
        foreach ([fn () => $this->servicio()->completar($this->ana, 999, self::COMENTARIO), fn () => $this->servicio()->cancelar($this->ana, 999, 'Obra cerrada')] as $accion) {
            try {
                $accion();
                self::fail('Se esperaba VisitaNoEncontrada');
            } catch (VisitaNoEncontrada) {
                self::assertFalse($this->app()->inTransaction());
            }
        }
    }

    private function errores(callable $accion): ErroresDeNegocio
    {
        try {
            $accion();
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
