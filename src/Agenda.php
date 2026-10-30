<?php

declare(strict_types=1);

namespace App;

/** Agenda de un técnico en un día: sus visitas (en cualquier estado, por hora) y los huecos libres. */
final readonly class Agenda
{
    /**
     * @param list<Visita> $visitas
     * @param list<Hueco> $huecos
     */
    public function __construct(
        public int $tecnicoId,
        public string $tecnicoNombre,
        public string $fecha,
        public array $visitas,
        public array $huecos,
    ) {
    }
}
