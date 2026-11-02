<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\BaseDatosTest;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cada CHECK del esquema rechaza su caso, y los valores límite válidos se aceptan.
 * Se comprueba el SQLSTATE 23514 (check_violation) y el nombre de la restricción que falla.
 */
final class RestriccionesTest extends BaseDatosTest
{
    private const VISITA_VALIDA = [
        'tecnico_id' => 4,
        'obra_id' => 1,
        'fecha' => '2030-01-15',
        'hora_inicio' => '10:00',
        'duracion_min' => 60,
        'desplazamiento_min' => 30,
        'origen_tipo' => 'SEDE',
        'origen_visita_id' => null,
        'estado' => 'Programada',
        'comentario_cierre' => null,
        'motivo_cancelacion' => null,
    ];

    /** @param array<string, mixed> $cambios */
    private function insertarVisita(array $cambios): void
    {
        $sentencia = $this->admin()->prepare(
            'INSERT INTO visitas (tecnico_id, obra_id, fecha, hora_inicio, duracion_min, desplazamiento_min,
                                  origen_tipo, origen_visita_id, estado, comentario_cierre, motivo_cancelacion)
             VALUES (:tecnico_id, :obra_id, :fecha, :hora_inicio, :duracion_min, :desplazamiento_min,
                     :origen_tipo, :origen_visita_id, :estado, :comentario_cierre, :motivo_cancelacion)'
        );
        $sentencia->execute(array_merge(self::VISITA_VALIDA, $cambios));
    }

    /** @param array<string, mixed> $datos */
    private function insertarUsuario(array $datos): void
    {
        $sentencia = $this->admin()->prepare(
            'INSERT INTO usuarios (email, password_hash, rol, tecnico_id) VALUES (:email, :hash, :rol, :tecnico_id)'
        );
        $sentencia->execute(array_merge(['email' => 'prueba@demo.local', 'hash' => 'no-es-un-hash'], $datos));
    }

    private function esperarRestriccion(string $restriccion, callable $operacion): void
    {
        try {
            $operacion();
            self::fail("Se esperaba que la restricción {$restriccion} rechazara la fila");
        } catch (PDOException $e) {
            self::assertSame('23514', $e->getCode(), $e->getMessage());
            self::assertStringContainsString($restriccion, $e->getMessage());
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function visitasInvalidas(): array
    {
        return [
            'duración 0' => [['duracion_min' => 0], 'ck_visitas_duracion'],
            'duración 541' => [['hora_inicio' => '08:00', 'duracion_min' => 541], 'ck_visitas_duracion'],
            'desplazamiento negativo' => [['desplazamiento_min' => -1], 'ck_visitas_desplazamiento'],
            'desplazamiento 241' => [['desplazamiento_min' => 241], 'ck_visitas_desplazamiento'],
            'inicio 07:59' => [['hora_inicio' => '07:59'], 'ck_visitas_hora_inicio'],
            'fin 17:01' => [['hora_inicio' => '16:01', 'duracion_min' => 60], 'ck_visitas_fin_jornada'],
            'inicio 16:00 con 540 min (vuelta a medianoche)' => [['hora_inicio' => '16:00', 'duracion_min' => 540], 'ck_visitas_fin_jornada'],
            'estado inválido' => [['estado' => 'Borrador'], 'ck_visitas_estado'],
            'origen inválido' => [['origen_tipo' => 'CASA'], 'ck_visitas_origen_tipo'],
            'Completada sin comentario' => [['estado' => 'Completada'], 'ck_visitas_completada_comentario'],
            'Cancelada sin motivo' => [['estado' => 'Cancelada'], 'ck_visitas_cancelada_motivo'],
            'origen SEDE con origen_visita_id' => [['origen_tipo' => 'SEDE', 'origen_visita_id' => 1], 'ck_visitas_origen'],
            'origen VISITA sin origen_visita_id' => [['origen_tipo' => 'VISITA', 'origen_visita_id' => null], 'ck_visitas_origen'],
        ];
    }

    #[DataProvider('visitasInvalidas')]
    public function testElCheckRechazaLaVisitaInvalida(array $cambios, string $restriccion): void
    {
        $this->esperarRestriccion($restriccion, fn () => $this->insertarVisita($cambios));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function visitasEnElLimite(): array
    {
        return [
            'duración 15' => [['duracion_min' => 15]],
            'duración 540 desde las 08:00' => [['hora_inicio' => '08:00', 'duracion_min' => 540]],
            'desplazamiento 0 (desde la sede o en la misma obra)' => [['desplazamiento_min' => 0]],
            'desplazamiento 30' => [['desplazamiento_min' => 30]],
            'desplazamiento 240' => [['desplazamiento_min' => 240]],
            'inicio 08:00' => [['hora_inicio' => '08:00']],
            'fin a las 17:00 justas' => [['hora_inicio' => '16:00', 'duracion_min' => 60]],
            'origen VISITA con origen_visita_id' => [['origen_tipo' => 'VISITA', 'origen_visita_id' => 12]],
            'Completada con comentario' => [['estado' => 'Completada', 'comentario_cierre' => 'Trabajo revisado sin incidencias.']],
            'Cancelada con motivo' => [['estado' => 'Cancelada', 'motivo_cancelacion' => 'Lluvia intensa']],
        ];
    }

    #[DataProvider('visitasEnElLimite')]
    public function testElCheckAceptaElValorLimite(array $cambios): void
    {
        $this->insertarVisita($cambios);

        self::assertSame(13, $this->valor('SELECT COUNT(*) FROM visitas'));
    }

    public function testElCheckRechazaRolTecnicoSinTecnicoId(): void
    {
        $this->esperarRestriccion(
            'ck_usuarios_rol_tecnico',
            fn () => $this->insertarUsuario(['rol' => 'TECNICO', 'tecnico_id' => null])
        );
    }

    public function testElCheckRechazaRolPlanificadorConTecnicoId(): void
    {
        $this->esperarRestriccion(
            'ck_usuarios_rol_tecnico',
            fn () => $this->insertarUsuario(['rol' => 'PLANIFICADOR', 'tecnico_id' => 1])
        );
    }

    public function testElCheckRechazaRolInvalido(): void
    {
        $this->esperarRestriccion(
            'ck_usuarios_rol',
            fn () => $this->insertarUsuario(['rol' => 'ADMIN', 'tecnico_id' => null])
        );
    }

    public function testElCheckAceptaUsuariosValidos(): void
    {
        $this->insertarUsuario(['email' => 'tecnico@demo.local', 'rol' => 'TECNICO', 'tecnico_id' => 1]);
        $this->insertarUsuario(['email' => 'plan@demo.local', 'rol' => 'PLANIFICADOR', 'tecnico_id' => null]);

        self::assertSame(2, $this->valor('SELECT COUNT(*) FROM usuarios'));
    }
}
