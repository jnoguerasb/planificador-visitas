<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/** La visita pedida no existe (la API responde 404). */
final class VisitaNoEncontrada extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("No existe la visita {$id}");
    }
}
