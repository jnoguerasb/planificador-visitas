<?php

declare(strict_types=1);

namespace App;

/** Usuario autenticado. $tecnicoId solo tiene valor si el rol es TECNICO. */
final readonly class Usuario
{
    public const PLANIFICADOR = 'PLANIFICADOR';
    public const TECNICO = 'TECNICO';

    public function __construct(
        public int $id,
        public string $rol,
        public ?int $tecnicoId,
    ) {
    }

    public function esPlanificador(): bool
    {
        return $this->rol === self::PLANIFICADOR;
    }

    /** Técnico con su ficha de técnico asociada. Un TECNICO sin ficha no puede hacer nada. */
    public function esTecnico(): bool
    {
        return $this->rol === self::TECNICO && $this->tecnicoId !== null;
    }
}
