<?php

declare(strict_types=1);

namespace App;

use PDO;

/** Técnicos, obras y sede. También el bloqueo del técnico con el que empieza cada escritura. */
final class CatalogoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, string> Técnicos activos, id => nombre, por nombre. */
    public function tecnicosActivos(): array
    {
        $filas = $this->pdo->query('SELECT id, nombre FROM tecnicos WHERE activo ORDER BY nombre, id')->fetchAll();

        return array_column($filas, 'nombre', 'id');
    }

    /** Nombre de un técnico, activo o no; null si no existe. */
    public function nombreTecnico(int $id): ?string
    {
        $sentencia = $this->pdo->prepare('SELECT nombre FROM tecnicos WHERE id = ?');
        $sentencia->execute([$id]);
        $nombre = $sentencia->fetchColumn();

        return $nombre === false ? null : $nombre;
    }

    /** @return list<array{id: int, nombre: string, direccion: string, municipio: string}> Por nombre. */
    public function obras(): array
    {
        return $this->pdo->query('SELECT id, nombre, direccion, municipio FROM obras ORDER BY nombre, id')->fetchAll();
    }

    /** @return array<int, string> id => nombre. */
    public function nombresObras(): array
    {
        $filas = $this->pdo->query('SELECT id, nombre FROM obras ORDER BY id')->fetchAll();

        return array_column($filas, 'nombre', 'id');
    }

    /** @return array{nombre: string, direccion: string, municipio: string}|null */
    public function sede(): ?array
    {
        $fila = $this->pdo->query('SELECT nombre, direccion, municipio FROM sede ORDER BY id LIMIT 1')->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Bloquea la fila del técnico hasta el final de la transacción. Así dos escrituras sobre el
     * mismo técnico se hacen una detrás de otra y la segunda ve lo que ha guardado la primera.
     */
    public function bloquearTecnico(int $id): void
    {
        $this->pdo->prepare('SELECT id FROM tecnicos WHERE id = ? FOR UPDATE')->execute([$id]);
    }
}
