<?php

declare(strict_types=1);

namespace App;

use PDO;

final class UsuarioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Para el login: incluye el hash de la contraseña. El email no distingue mayúsculas.
     *
     * @return array{id: int, email: string, password_hash: string, rol: string, tecnico_id: ?int, activo: bool}|null
     */
    public function buscarPorEmail(string $email): ?array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT id, email, password_hash, rol, tecnico_id, activo FROM usuarios WHERE lower(email) = lower(?)'
        );
        $sentencia->execute([$email]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Lo que el navegador necesita saber del usuario: email, rol y técnico asociado (con su nombre).
     *
     * @return array{id: int, email: string, rol: string, tecnico_id: ?int, tecnico: ?string}|null
     */
    public function perfil(int $id): ?array
    {
        $sentencia = $this->pdo->prepare(<<<'SQL'
            SELECT u.id, u.email, u.rol, u.tecnico_id, t.nombre AS tecnico
              FROM usuarios u
              LEFT JOIN tecnicos t ON t.id = u.tecnico_id
             WHERE u.id = ? AND u.activo
            SQL);
        $sentencia->execute([$id]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    /** Usuario de la sesión. Un usuario desactivado deja de existir para la aplicación. */
    public function buscarActivoPorId(int $id): ?Usuario
    {
        $sentencia = $this->pdo->prepare('SELECT id, rol, tecnico_id FROM usuarios WHERE id = ? AND activo');
        $sentencia->execute([$id]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : new Usuario($fila['id'], $fila['rol'], $fila['tecnico_id']);
    }
}
