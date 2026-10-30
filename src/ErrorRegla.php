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
    // Fuera de la tabla de reglas del análisis: rutas o visitas que no existen (404) y errores internos (500).
    public const NO_ENCONTRADO = 'NO_ENCONTRADO';
    public const INTERNO = 'INTERNO';

    public function __construct(
        public string $regla,
        public string $mensaje,
        public ?string $campo = null,
    ) {
    }
}
