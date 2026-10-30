<?php

declare(strict_types=1);

namespace App;

/** Tramo libre de la agenda, [inicio, fin) en minutos. */
final readonly class Hueco
{
    public function __construct(
        public int $inicio,
        public int $fin,
    ) {
    }

    public function minutos(): int
    {
        return $this->fin - $this->inicio;
    }

    public function inicioTexto(): string
    {
        return Horas::aTexto($this->inicio);
    }

    public function finTexto(): string
    {
        return Horas::aTexto($this->fin);
    }
}
