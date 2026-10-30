<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * La operación no se ha hecho porque incumple reglas de negocio. Lleva todos los errores, para
 * devolverlos juntos al cliente. No es un fallo técnico: no va al registro de errores.
 */
final class ErroresDeNegocio extends RuntimeException
{
    /** @param list<ErrorRegla> $errores */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('Reglas incumplidas: ' . implode(', ', $this->reglas()));
    }

    public static function una(string $regla, string $mensaje, ?string $campo = null): self
    {
        return new self([new ErrorRegla($regla, $mensaje, $campo)]);
    }

    /** @return list<string> */
    public function reglas(): array
    {
        return array_map(fn (ErrorRegla $error) => $error->regla, $this->errores);
    }
}
