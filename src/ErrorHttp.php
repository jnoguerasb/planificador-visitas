<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/** Error de la petición en sí (no de las reglas de negocio), con su código HTTP y un mensaje para el usuario. */
final class ErrorHttp extends RuntimeException
{
    public function __construct(
        public readonly int $estado,
        public readonly string $regla,
        string $mensaje,
    ) {
        parent::__construct($mensaje);
    }

    /** La petición no se puede leer: no es JSON, está mal formada o es demasiado grande. */
    public static function peticionNoValida(string $mensaje): self
    {
        return new self(400, ErrorRegla::CAMPOS, $mensaje);
    }

    public static function noAutenticado(): self
    {
        return new self(401, ErrorRegla::PERMISO, 'Inicia sesión para continuar');
    }

    public static function credencialesIncorrectas(): self
    {
        return new self(401, ErrorRegla::PERMISO, 'Usuario o contraseña incorrectos');
    }

    public static function prohibido(string $mensaje = 'No tienes permiso para hacer esto'): self
    {
        return new self(403, ErrorRegla::PERMISO, $mensaje);
    }

    public static function noEncontrado(): self
    {
        return new self(404, ErrorRegla::NO_ENCONTRADO, 'No existe');
    }
}
