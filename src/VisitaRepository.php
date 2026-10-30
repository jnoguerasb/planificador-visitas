<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Lectura y escritura de visitas. Solo SELECT, INSERT y UPDATE: las visitas nunca se borran.
 * Las horas se leen como "HH:MM" y se pasan a minutos con Horas.
 */
final class VisitaRepository
{
    private const COLUMNAS_VISITA = <<<'SQL'
        SELECT v.id, v.tecnico_id, v.obra_id, o.nombre AS obra, v.fecha::text AS fecha,
               to_char(v.hora_inicio, 'HH24:MI') AS inicio, v.duracion_min, v.desplazamiento_min,
               v.estado, v.origen_visita_id, v.revisar_desplazamiento
          FROM visitas v
          JOIN obras o ON o.id = v.obra_id
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Visita> Todas las del técnico ese día, en cualquier estado, por hora. */
    public function delDia(int $tecnicoId, string $fecha): array
    {
        $sentencia = $this->pdo->prepare(self::COLUMNAS_VISITA . ' WHERE v.tecnico_id = ? AND v.fecha = ? ORDER BY v.hora_inicio, v.id');
        $sentencia->execute([$tecnicoId, $fecha]);

        return array_map($this->aVisita(...), $sentencia->fetchAll());
    }

    public function buscar(int $id): ?Visita
    {
        $sentencia = $this->pdo->prepare(self::COLUMNAS_VISITA . ' WHERE v.id = ?');
        $sentencia->execute([$id]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : $this->aVisita($fila);
    }

    /** Inserta una visita Programada ya validada y devuelve su id. */
    public function insertar(Visita $visita, int $creadaPor, DateTimeImmutable $creadaEn): int
    {
        $sentencia = $this->pdo->prepare(<<<'SQL'
            INSERT INTO visitas (tecnico_id, obra_id, fecha, hora_inicio, duracion_min, desplazamiento_min,
                                 origen_tipo, origen_visita_id, estado, creada_por, creada_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Programada', ?, ?)
            RETURNING id
            SQL);
        $sentencia->execute([
            $visita->tecnicoId,
            $visita->obraId,
            $visita->fecha,
            Horas::aTexto($visita->inicio),
            $visita->duracion,
            $visita->desplazamiento,
            $visita->origenTipo(),
            $visita->origenVisitaId,
            $creadaPor,
            self::marcaDeTiempo($creadaEn),
        ]);

        return (int) $sentencia->fetchColumn();
    }

    /**
     * Al programar una visita justo antes de otra: la siguiente pasa a salir de la nueva con el
     * desplazamiento indicado. El planificador acaba de fijarlo, así que deja de estar para revisar.
     */
    public function enlazarSiguiente(int $siguienteId, int $nuevaId, int $desplazamiento): void
    {
        $sentencia = $this->pdo->prepare(<<<'SQL'
            UPDATE visitas
               SET origen_tipo = 'VISITA', origen_visita_id = ?, desplazamiento_min = ?, revisar_desplazamiento = FALSE
             WHERE id = ?
            SQL);
        $sentencia->execute([$nuevaId, $desplazamiento, $siguienteId]);
        $this->comprobarUnaFila($sentencia->rowCount(), $siguienteId);
    }

    /** Tras una cancelación: nuevo origen (null = sede), mismo desplazamiento, marcada para revisar. */
    public function reasignarOrigen(CambioOrigen $cambio): void
    {
        $sentencia = $this->pdo->prepare(<<<'SQL'
            UPDATE visitas
               SET origen_tipo = ?, origen_visita_id = ?, revisar_desplazamiento = TRUE
             WHERE id = ?
            SQL);
        $sentencia->execute([$cambio->origenTipo(), $cambio->origenVisitaId, $cambio->visitaId]);
        $this->comprobarUnaFila($sentencia->rowCount(), $cambio->visitaId);
    }

    public function completar(int $id, string $comentario, int $usuarioId, DateTimeImmutable $en): void
    {
        $sentencia = $this->pdo->prepare(<<<'SQL'
            UPDATE visitas
               SET estado = 'Completada', comentario_cierre = ?, completada_por = ?, completada_en = ?
             WHERE id = ? AND estado = 'Programada'
            SQL);
        $sentencia->execute([$comentario, $usuarioId, self::marcaDeTiempo($en), $id]);
        $this->comprobarUnaFila($sentencia->rowCount(), $id);
    }

    public function cancelar(int $id, string $motivo, int $usuarioId, DateTimeImmutable $en): void
    {
        $sentencia = $this->pdo->prepare(<<<'SQL'
            UPDATE visitas
               SET estado = 'Cancelada', motivo_cancelacion = ?, cancelada_por = ?, cancelada_en = ?
             WHERE id = ? AND estado = 'Programada'
            SQL);
        $sentencia->execute([$motivo, $usuarioId, self::marcaDeTiempo($en), $id]);
        $this->comprobarUnaFila($sentencia->rowCount(), $id);
    }

    /**
     * Listado con filtros opcionales (null = sin filtrar) que se combinan con Y.
     * Ordenado por fecha, hora y nombre del técnico. El autor del cierre es el nombre del técnico
     * o, si fue el planificador, su email.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(?int $tecnicoId, ?string $fecha, ?string $estado): array
    {
        $sentencia = $this->pdo->prepare(<<<'SQL'
            SELECT v.id, v.fecha::text AS fecha,
                   to_char(v.hora_inicio, 'HH24:MI') AS inicio,
                   to_char(v.hora_inicio + make_interval(mins => v.duracion_min), 'HH24:MI') AS fin,
                   v.tecnico_id, t.nombre AS tecnico, v.obra_id, o.nombre AS obra, o.municipio,
                   v.desplazamiento_min, v.origen_tipo, v.revisar_desplazamiento, v.estado,
                   v.comentario_cierre, v.motivo_cancelacion,
                   COALESCE(tc.nombre, uc.email) AS completada_por,
                   to_char(v.completada_en, 'YYYY-MM-DD HH24:MI') AS completada_en,
                   COALESCE(tx.nombre, ux.email) AS cancelada_por,
                   to_char(v.cancelada_en, 'YYYY-MM-DD HH24:MI') AS cancelada_en
              FROM visitas v
              JOIN tecnicos t ON t.id = v.tecnico_id
              JOIN obras o ON o.id = v.obra_id
              LEFT JOIN usuarios uc ON uc.id = v.completada_por
              LEFT JOIN tecnicos tc ON tc.id = uc.tecnico_id
              LEFT JOIN usuarios ux ON ux.id = v.cancelada_por
              LEFT JOIN tecnicos tx ON tx.id = ux.tecnico_id
             WHERE (CAST(:tecnico_nulo AS INT) IS NULL OR v.tecnico_id = :tecnico)
               AND (CAST(:fecha_nula AS DATE) IS NULL OR v.fecha = :fecha)
               AND (CAST(:estado_nulo AS VARCHAR) IS NULL OR v.estado = :estado)
             ORDER BY v.fecha, v.hora_inicio, t.nombre, v.id
            SQL);
        // Cada parámetro dos veces con nombres distintos: con sentencias preparadas reales no se repiten nombres.
        $sentencia->execute([
            'tecnico_nulo' => $tecnicoId, 'tecnico' => $tecnicoId,
            'fecha_nula' => $fecha, 'fecha' => $fecha,
            'estado_nulo' => $estado, 'estado' => $estado,
        ]);

        return $sentencia->fetchAll();
    }

    /** @param array<string, mixed> $fila */
    private function aVisita(array $fila): Visita
    {
        return new Visita(
            $fila['id'],
            $fila['tecnico_id'],
            $fila['obra_id'],
            $fila['obra'],
            $fila['fecha'],
            Horas::aMinutos($fila['inicio']),
            $fila['duracion_min'],
            $fila['desplazamiento_min'],
            $fila['estado'],
            $fila['origen_visita_id'],
            $fila['revisar_desplazamiento'],
        );
    }

    /** Las marcas de tiempo se guardan en hora de Madrid (columnas TIMESTAMP sin zona). */
    private static function marcaDeTiempo(DateTimeImmutable $momento): string
    {
        return $momento->setTimezone(new DateTimeZone(Reloj::ZONA_HORARIA))->format('Y-m-d H:i:s');
    }

    /** Defensa: con el técnico bloqueado no debería pasar nunca. Si pasa, la transacción se deshace. */
    private function comprobarUnaFila(int $filas, int $id): void
    {
        if ($filas !== 1) {
            throw new RuntimeException("La visita {$id} no se ha podido actualizar");
        }
    }
}
