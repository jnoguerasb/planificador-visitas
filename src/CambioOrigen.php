<?php

declare(strict_types=1);

namespace App;

/** Nuevo origen de una visita tras cancelar la visita de la que salía. Se marca "Revisar desplazamiento". */
final readonly class CambioOrigen
{
    public function __construct(
        public int $visitaId,
        public ?int $origenVisitaId,
    ) {
    }

    public function origenTipo(): string
    {
        return $this->origenVisitaId === null ? Visita::ORIGEN_SEDE : Visita::ORIGEN_VISITA;
    }
}
