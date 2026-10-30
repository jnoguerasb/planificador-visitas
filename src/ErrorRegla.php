<?php

declare(strict_types=1);

namespace App;

/** Una regla incumplida, con el mensaje para el usuario y, si aplica, el campo del formulario. */
final readonly class ErrorRegla
{
    public const CAMPOS = 'CAMPOS';
    public const PERMISO = 'PERMISO';
    public const JORNADA = 'JORNADA';
    public const SOLAPE = 'SOLAPE';
    public const DESPLAZAMIENTO = 'DESPLAZAMIENTO';
    public const ESTADO = 'ESTADO';
    public const MOMENTO = 'MOMENTO';
    public const TEXTO = 'TEXTO';

    public function __construct(
        public string $regla,
        public string $mensaje,
        public ?string $campo = null,
    ) {
    }
}
