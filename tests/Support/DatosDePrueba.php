<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

/**
 * Ayudas para preparar y leer visitas directamente con el usuario propietario.
 * La clase que lo usa define FECHA (fecha por defecto de las visitas de prueba).
 */
trait DatosDePrueba
{
    /**
     * Inserta una visita y devuelve su id. Por defecto, la partida común: técnico 1, obra 1,
     * de 10:00 a 11:00, 30 min desde la sede, Programada.
     *
     * @param array<string, mixed> $campos Columnas que cambian.
     */
    protected function insertarVisita(array $campos = []): int
    {
        $fila = array_merge([
            'tecnico_id' => 1,
            'obra_id' => 1,
            'fecha' => self::FECHA,
            'hora_inicio' => '10:00',
            'duracion_min' => 60,
            'desplazamiento_min' => 30,
            'origen_tipo' => 'SEDE',
            'origen_visita_id' => null,
            'revisar_desplazamiento' => 'false',
            'estado' => 'Programada',
            'comentario_cierre' => null,
            'motivo_cancelacion' => null,
        ], $campos);

        $sentencia = $this->admin()->prepare(<<<'SQL'
            INSERT INTO visitas (tecnico_id, obra_id, fecha, hora_inicio, duracion_min, desplazamiento_min, origen_tipo,
                                 origen_visita_id, revisar_desplazamiento, estado, comentario_cierre, motivo_cancelacion)
            VALUES (:tecnico_id, :obra_id, :fecha, :hora_inicio, :duracion_min, :desplazamiento_min, :origen_tipo,
                    :origen_visita_id, :revisar_desplazamiento, :estado, :comentario_cierre, :motivo_cancelacion)
            RETURNING id
            SQL);
        $sentencia->execute($fila);

        return (int) $sentencia->fetchColumn();
    }

    /** @return array<string, mixed> La fila completa de una visita. */
    protected function fila(int $id): array
    {
        $sentencia = $this->admin()->prepare(<<<'SQL'
            SELECT v.*, to_char(v.hora_inicio, 'HH24:MI') AS inicio,
                   to_char(v.creada_en, 'YYYY-MM-DD HH24:MI') AS creada,
                   to_char(v.completada_en, 'YYYY-MM-DD HH24:MI') AS completada,
                   to_char(v.cancelada_en, 'YYYY-MM-DD HH24:MI') AS cancelada
              FROM visitas v WHERE v.id = ?
            SQL);
        $sentencia->execute([$id]);
        $fila = $sentencia->fetch();
        if ($fila === false) {
            throw new RuntimeException("No existe la visita {$id}");
        }

        return $fila;
    }

    protected function numeroDeVisitas(): int
    {
        return (int) $this->valor('SELECT COUNT(*) FROM visitas');
    }
}
